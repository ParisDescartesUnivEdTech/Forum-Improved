<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Discussion services
 *
 * @package   mod_forumplusone
 * @copyright Copyright (c) 2013 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU GPL v2 or later
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 */

namespace mod_forumplusone\service;

use mod_forumplusone\attachments;
use mod_forumplusone\event\discussion_created;
use mod_forumplusone\response\json_response;
use mod_forumplusone\upload_file;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__DIR__).'/response/json_response.php');
require_once(dirname(__DIR__).'/upload_file.php');
require_once(dirname(dirname(__DIR__)).'/lib.php');

/**
 * @package   mod_forumplusone
 * @copyright Copyright (c) 2013 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU GPL v2 or later
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 */
class discussion_service {
    /**
     * @var \moodle_database
     */
    protected $db;

    public function __construct(\moodle_database $db = null) {
        global $DB;

        if (is_null($db)) {
            $db = $DB;
        }
        $this->db = $db;
    }

    /**
     * Does all the grunt work for adding a discussion
     *
     * @param object $course
     * @param object $cm
     * @param object $forum
     * @param \context_module $context
     * @param array $options These override default post values, EG: set the post message with this
     * @return json_response
     */
    public function handle_add_discussion($course, $cm, $forum, $context, array $options) {
        global $PAGE, $OUTPUT;

        $uploader = new upload_file(
            new attachments($forum, $context), \mod_forumplusone_post_form::attachment_options($forum)
        );

        $discussion = $this->create_discussion_object($forum, $context, $options);
        $errors = $this->validate_discussion($cm, $forum, $context, $discussion, $uploader);

        if (!empty($errors)) {
            /** @var \mod_forumplusone_renderer $renderer */
            $renderer = $PAGE->get_renderer('mod_forumplusone');

            return new json_response((object) array(
                'errors' => true,
                'html'   => $renderer->validation_errors($errors),
            ));
        }
        $this->save_discussion($discussion, $uploader);
        $this->trigger_discussion_created($course, $context, $cm, $forum, $discussion);

        $message = get_string('postaddedsuccess', 'forumplusone');

        $renderer = $PAGE->get_renderer('mod_forumplusone');

        return new json_response((object) array(
            'eventaction'      => 'discussioncreated',
            'discussionid'     => (int) $discussion->id,
            'livelog'          => $message,
            'notificationhtml' => $OUTPUT->notification($message, 'notifysuccess'),
            'html'             => $renderer->render_discussionsview($forum),
        ));
    }

    /**
     * Creates the discussion object to be saved.
     *
     * @param object $forum
     * @param \context_module $context
     * @param array $options These override default post values, EG: set the post message with this
     * @return \stdClass
     */
    public function create_discussion_object($forum, $context, array $options = array()) {
        $discussion = (object) array(
            'name'          => '',
            'course'        => $forum->course,
            'forum'         => $forum->id,
            'groupid'       => -1,
            'timestart'     => 0,
            'timeend'       => 0,
            'message'       => '',
            'messageformat' => FORMAT_MOODLE,
            'messagetrust'  => trusttext_trusted($context),
            'mailnow'       => 0,
            'reveal'        => 0,
        );
        foreach ($options as $name => $value) {
            if (property_exists($discussion, $name)) {
                $discussion->$name = $value;
            }
        }
        return $discussion;
    }

    /**
     * Validates the submitted discussion and any submitted files
     *
     * @param object $cm
     * @param object $forum
     * @param \context_module $context
     * @param object $discussion
     * @param upload_file $uploader
     * @return moodle_exception[]
     */
    public function validate_discussion($cm, $forum, $context, $discussion, upload_file $uploader) {
        $errors = array();
        if (!forumplusone_user_can_post_discussion($forum, $discussion->groupid, -1, $cm, $context)) {
            $errors[] = new \moodle_exception('nopostforum', 'forumplusone');
        }

        $thresholdwarning = forumplusone_check_throttling($forum, $cm);
        if ($thresholdwarning !== false && $thresholdwarning->canpost === false) {
            $errors[] = new \moodle_exception($thresholdwarning->errorcode, $thresholdwarning->module, $thresholdwarning->additional);
        }

        $subject = trim($discussion->name);
        if (empty($subject)) {
            $errors[] = new \moodle_exception('discnameisrequired', 'forumplusone');
        }
        if (forumplusone_str_empty($discussion->message)) {
            $errors[] = new \moodle_exception('messageisrequired', 'forumplusone');
        }
        if ($uploader->was_file_uploaded()) {
            try {
                $uploader->validate_files();
            } catch (\Exception $e) {
                $errors[] = $e;
            }
        }
        return $errors;
    }

    /**
     * Save the discussion to the DB
     *
     * @param object $discussion
     * @param upload_file $uploader
     */
    public function save_discussion($discussion, upload_file $uploader) {
        $message        = '';
        $discussion->id = forumplusone_add_discussion($discussion, null, $message);

        $file = $uploader->process_file_upload($discussion->firstpost);
        if (!is_null($file)) {
            $this->db->set_field('forumplusone_posts', 'attachment', 1, array('id' => $discussion->firstpost));
        }
    }

    /**
     * Log, update completion info and trigger event
     *
     * @param object $course
     * @param \context_module $context
     * @param object $cm
     * @param object $forum
     * @param object $discussion
     */
    public function trigger_discussion_created($course, \context_module $context, $cm, $forum, $discussion) {
        global $CFG;

        require_once($CFG->libdir.'/completionlib.php');

        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) &&
            ($forum->completiondiscussions || $forum->completionposts)
        ) {
            $completion->update_state($cm, COMPLETION_COMPLETE);
        }

        $params = array(
            'context'  => $context,
            'objectid' => $discussion->id,
            'other'    => array(
                'forumid' => $forum->id,
            )
        );
        $event = discussion_created::create($params);
        $event->add_record_snapshot('forumplusone_discussions', $discussion);
        $event->trigger();
    }

    /**
     * Get a discussion posts and related info
     *
     * @param $discussionid
     * @return array
     */
    public function get_posts($discussionid) {
        global $PAGE, $DB, $CFG, $COURSE, $USER;

        $discussion = $DB->get_record('forumplusone_discussions', array('id' => $discussionid), '*', MUST_EXIST);
        $forum      = $PAGE->activityrecord;
        $course     = $COURSE;
        $cm         = get_coursemodule_from_id('forumplusone', $PAGE->cm->id, $course->id, false, MUST_EXIST); // Cannot use cm_info because it is read only.
        $context    = $PAGE->context;

        if ($forum->type == 'news') {
            if (!($USER->id == $discussion->userid || (($discussion->timestart == 0
                        || $discussion->timestart <= time())
                    && ($discussion->timeend == 0 || $discussion->timeend > time())))
            ) {
                print_error('invaliddiscussionid', 'forumplusone', "$CFG->wwwroot/mod/forumplusone/view.php?f=$forum->id");
            }
        }
        if (!$post = forumplusone_get_post_full($discussion->firstpost)) {
            print_error("notexists", 'forumplusone', "$CFG->wwwroot/mod/forumplusone/view.php?f=$forum->id");
        }
        if (!forumplusone_user_can_see_post($forum, $discussion, $post, null, $cm)) {
            print_error('nopermissiontoview', 'forumplusone', "$CFG->wwwroot/mod/forumplusone/view.php?f=$forum->id");
        }

        $posts        = forumplusone_get_all_discussion_posts($discussion->id);
        $canreply     = forumplusone_user_can_post($forum, $discussion, $USER, $cm, $course, $context);

        forumplusone_get_ratings_for_posts($context, $forum, $posts);

        return array($cm, $discussion, $posts, $canreply);
    }


    /**
     * Render a discussion overview (basically the first post)
     *
     * @param int $discussionid
     * @return string
     * @throws \coding_exception
     */
    public function render_discussion($discussionid, $fullthread = false) {
        global $PAGE;

        $renderer = $PAGE->get_renderer('mod_forumplusone');

        list($cm, $discussion, $posts, $canreply) = $this->get_posts($discussionid);

        if (!array_key_exists($discussion->firstpost, $posts)) {
            throw new \coding_exception('Failed to find discussion post');
        }
        return $renderer->discussion($cm, $discussion, $posts[$discussion->firstpost], $fullthread, $posts, $canreply);
    }

    /**
     * Render a full discussion thread
     *
     * @param int $discussionid
     * @return string
     * @throws \coding_exception
     */
    public function render_full_thread($discussionid) {
        return $this->render_discussion($discussionid, true);
    }

    /**
     * Change the state of a discussion
     *
     * @param object $forum
     * @param object $discussion
     * @param int    $state
     * @return json_response
     */
    public function handle_change_state($forum, $discussion, $state, $context) {
        $response = array();

        try {
            if ($state == FORUMPLUSONE_DISCUSSION_STATE_OPEN)
                forumplusone_discussion_open($forum, $discussion);
            if ($state == FORUMPLUSONE_DISCUSSION_STATE_CLOSE)
                forumplusone_discussion_close($forum, $discussion);
            if ($state == FORUMPLUSONE_DISCUSSION_STATE_HIDDEN)
                forumplusone_discussion_hide($forum, $discussion);

            $params = array(
                'context' => $context,
                'objectid' => $discussion->id,
                'other' => array(
                    'forumid' => $forum->id,
                )
            );
            $event = \mod_forumplusone\event\discussion_updated::create($params);
            $event->trigger();

            $response['errorCode'] = 0;
        }
        catch (coding_exception $e) {
            $response['errorCode'] = $e->a;
            $response['errorMsg'] = get_string($e->a, 'forumplusone');
        }


        return new json_response((object) $response);
    }
}
