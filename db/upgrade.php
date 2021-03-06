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
 * This file keeps track of upgrades to
 * the forum module
 *
 * Sometimes, changes between versions involve
 * alterations to database structures and other
 * major things that may break installations.
 *
 * The upgrade function in this file will attempt
 * to perform all the necessary actions to upgrade
 * your older installation to the current version.
 *
 * If there's something it cannot do itself, it
 * will tell you what you need to do.
 *
 * The commands in here will all be database-neutral,
 * using the methods of database_manager class
 *
 * Please do not forget to use upgrade_set_timeout()
 * before any action that may take longer time to finish.
 *
 * @package   mod_forumplusone
 * @copyright 2003 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU GPL v2 or later
 * @copyright Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 */

function xmldb_forumplusone_upgrade($oldversion) {
    global $CFG, $DB, $OUTPUT;

    $dbman = $DB->get_manager(); // loads ddl manager and xmldb classes

//===== 1.9.0 upgrade line ======//

    if ($oldversion < 2011112801) {
    /// FORUMPLUSONE UPGRADES
        // Rename field forumplusone on table forumplusone_discussions to forum
        $table = new xmldb_table('forumplusone_discussions');
        $field = new xmldb_field('forumplusone', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'course');

        // Launch rename field forumplusone
        $dbman->rename_field($table, $field, 'forum');

        // Rename field forumplusone on table forumplusone_subscriptions to forum
        $table = new xmldb_table('forumplusone_subscriptions');
        $field = new xmldb_field('forumplusone', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'userid');

        // Launch rename field forumplusone
        $dbman->rename_field($table, $field, 'forum');

        // Rename field forumplusoneid on table forumplusone_read to forumid
        $table = new xmldb_table('forumplusone_read');
        $field = new xmldb_field('forumplusoneid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'userid');

        // Launch rename field forumplusoneid
        $dbman->rename_field($table, $field, 'forumid');

        // forumplusone_discussion_subscripts was too long of a name
        // Define table forumplusone_discussion_subscripts to be renamed to forumplusone_subs_disc
        $table = new xmldb_table('forumplusone_discussion_subscripts');

        // Launch rename table for forumplusone_discussion_subscripts
        $dbman->rename_table($table, 'forumplusone_subs_disc');
    /// FORUMPLUSONE UPGRADES END

        //MDL-13866 - send forum ratins to gradebook again
        require_once($CFG->dirroot.'/mod/forumplusone/lib.php');
        forumplusone_upgrade_grades();
        upgrade_mod_savepoint(true, 2011112801, 'forumplusone');
    }

    if ($oldversion < 2011112802) {
    /// Define field completiondiscussions to be added to forum
        $table = new xmldb_table('forumplusone');
        $field = new xmldb_field('completiondiscussions');
        $field->set_attributes(XMLDB_TYPE_INTEGER, '9', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'blockperiod');

    /// Launch add field completiondiscussions
        if(!$dbman->field_exists($table,$field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('completionreplies');
        $field->set_attributes(XMLDB_TYPE_INTEGER, '9', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'completiondiscussions');

    /// Launch add field completionreplies
        if(!$dbman->field_exists($table,$field)) {
            $dbman->add_field($table, $field);
        }

    /// Define field completionposts to be added to forum
        $field = new xmldb_field('completionposts');
        $field->set_attributes(XMLDB_TYPE_INTEGER, '9', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'completionreplies');

    /// Launch add field completionposts
        if(!$dbman->field_exists($table,$field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2011112802, 'forumplusone');
    }

    if ($oldversion < 2011112803) {

        /////////////////////////////////////
        /// new file storage upgrade code ///
        /////////////////////////////////////

        $fs = get_file_storage();

        $empty = $DB->sql_empty(); // silly oracle empty string handling workaround

        $sqlfrom = "FROM {forumplusone_posts} p
                    JOIN {forumplusone_discussions} d ON d.id = p.discussion
                    JOIN {forumplusone} f ON f.id = d.forum
                    JOIN {modules} m ON m.name = 'forumplusone'
                    JOIN {course_modules} cm ON (cm.module = m.id AND cm.instance = f.id)
                   WHERE p.attachment <> '$empty' AND p.attachment <> '1'";

        $count = $DB->count_records_sql("SELECT COUNT('x') $sqlfrom");

        $rs = $DB->get_recordset_sql("SELECT p.id, p.attachment, p.userid, d.forum, f.course, cm.id AS cmid $sqlfrom ORDER BY f.course, f.id, d.id");
        if ($rs->valid()) {

            $pbar = new progress_bar('migrateforumfiles', 500, true);

            $i = 0;
            foreach ($rs as $post) {
                $i++;
                upgrade_set_timeout(60); // set up timeout, may also abort execution
                $pbar->update($i, $count, "Migrating forum posts - $i/$count.");


                $attachmentmigrated = false;

                $basepath = "$CFG->dataroot/$post->course/$CFG->moddata/forumplusone/$post->forum/$post->id";
                $files    = get_directory_list($basepath);
                foreach ($files as $file) {
                    $filepath = "$basepath/$file";

                    if (!is_readable($filepath)) {
                        //file missing??
                        echo $OUTPUT->notification("File not readable, skipping: ".$filepath);
                        $post->attachment = '';
                        $DB->update_record('forumplusone_posts', $post);
                        continue;
                    }
                    $context = context_module::instance($post->cmid);

                    $filearea = 'attachment';
                    $filename = clean_param(pathinfo($filepath, PATHINFO_BASENAME), PARAM_FILE);
                    if ($filename === '') {
                        echo $OUTPUT->notification("Unsupported post filename, skipping: ".$filepath);
                        $post->attachment = '';
                        $DB->update_record('forumplusone_posts', $post);
                        continue;
                    }
                    if (!$fs->file_exists($context->id, 'mod_forumplusone', $filearea, $post->id, '/', $filename)) {
                        $file_record = array('contextid'=> $context->id,
                                             'component'=> 'mod_forumplusone',
                                             'filearea' => $filearea,
                                             'itemid'   => $post->id,
                                             'filepath' => '/',
                                             'filename' => $filename,
                                             'userid'   => $post->userid);
                        if ($fs->create_file_from_pathname($file_record, $filepath)) {
                            $attachmentmigrated = true;
                            unlink($filepath);
                        }
                    }
                }
                if ($attachmentmigrated) {
                    $post->attachment = '1';
                    $DB->update_record('forumplusone_posts', $post);
                }

                // remove dirs if empty
                @rmdir("$CFG->dataroot/$post->course/$CFG->moddata/forumplusone/$post->forum/$post->id");
                @rmdir("$CFG->dataroot/$post->course/$CFG->moddata/forumplusone/$post->forum");
                @rmdir("$CFG->dataroot/$post->course/$CFG->moddata/forumplusone");
            }
        }
        $rs->close();

        upgrade_mod_savepoint(true, 2011112803, 'forumplusone');
    }

    if ($oldversion < 2011112804) {

    /// Define field maxattachments to be added to forum
        $table = new xmldb_table('forumplusone');
        $field = new xmldb_field('maxattachments', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '1', 'maxbytes');

    /// Conditionally launch add field maxattachments
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

    /// FORUMPLUSONE specific upgrades to maxattach and multiattach
        $field = new xmldb_field('maxattach', XMLDB_TYPE_INTEGER, '2', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '5');
        if ($dbman->field_exists($table, $field)) {
            $DB->execute("
                UPDATE {forumplusone}
                   SET maxattachments = maxattach
            ");

            $dbman->drop_field($table, $field);
        }
        $field = new xmldb_field('multiattach', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '1');
        if ($dbman->field_exists($table, $field)) {
            // This disabled attachments, so clear out maxattachments
            $DB->execute("
                UPDATE {forumplusone}
                   SET maxattachments = 0
                 WHERE multiattach = 0
            ");

            $dbman->drop_field($table, $field);
        }


    /// forum savepoint reached
        upgrade_mod_savepoint(true, 2011112804, 'forumplusone');
    }

    if ($oldversion < 2011112805) {

    /// Rename field format on table forumplusone_posts to messageformat
        $table = new xmldb_table('forumplusone_posts');
        $field = new xmldb_field('format', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'message');

    /// Launch rename field format
        $dbman->rename_field($table, $field, 'messageformat');

    /// forum savepoint reached
        upgrade_mod_savepoint(true, 2011112805, 'forumplusone');
    }

    if ($oldversion < 2011112806) {

    /// Define field messagetrust to be added to forumplusone_posts
        $table = new xmldb_table('forumplusone_posts');
        $field = new xmldb_field('messagetrust', XMLDB_TYPE_INTEGER, '2', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'messageformat');

    /// Launch add field messagetrust
        $dbman->add_field($table, $field);

    /// forum savepoint reached
        upgrade_mod_savepoint(true, 2011112806, 'forumplusone');
    }

    if ($oldversion < 2011112807) {
        $trustmark = '#####TRUSTTEXT#####';
        $rs = $DB->get_recordset_sql("SELECT * FROM {forumplusone_posts} WHERE message LIKE ?", array($trustmark.'%'));
        foreach ($rs as $post) {
            if (strpos($post->message, $trustmark) !== 0) {
                // probably lowercase in some DBs?
                continue;
            }
            $post->message      = str_replace($trustmark, '', $post->message);
            $post->messagetrust = 1;
            $DB->update_record('forumplusone_posts', $post);
        }
        $rs->close();

    /// forum savepoint reached
        upgrade_mod_savepoint(true, 2011112807, 'forumplusone');
    }

    if ($oldversion < 2011112808) {

    /// Define field introformat to be added to forum
        $table = new xmldb_table('forumplusone');
        $field = new xmldb_field('introformat', XMLDB_TYPE_INTEGER, '4', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'intro');

    /// Launch add field introformat
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // conditionally migrate to html format in intro
        if ($CFG->texteditors !== 'textarea') {
            $rs = $DB->get_recordset('forumplusone', array('introformat'=>FORMAT_MOODLE), '', 'id,intro,introformat');
            foreach ($rs as $f) {
                $f->intro       = text_to_html($f->intro, false, false, true);
                $f->introformat = FORMAT_HTML;
                $DB->update_record('forumplusone', $f);
                upgrade_set_timeout();
            }
            $rs->close();
        }

    /// forum savepoint reached
        upgrade_mod_savepoint(true, 2011112808, 'forumplusone');
    }

    /// Dropping all enums/check contraints from core. MDL-18577
    if ($oldversion < 2011112809) {

    /// Changing list of values (enum) of field type on table forum to none
        $table = new xmldb_table('forumplusone');
        $field = new xmldb_field('type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'general', 'course');

    /// Launch change of list of values for field type
        $dbman->drop_enum_from_field($table, $field);

    /// forum savepoint reached
        upgrade_mod_savepoint(true, 2011112809, 'forumplusone');
    }

    if ($oldversion < 2011112810) {

    /// Clean existing wrong rates. MDL-18227
        $DB->delete_records('forumplusone_ratings', array('post' => 0));

    /// forum savepoint reached
        upgrade_mod_savepoint(true, 2011112810, 'forumplusone');
    }

    if ($oldversion < 2011112811) {
        //migrate forumratings to the central rating table
        $table = new xmldb_table('forumplusone_ratings');
        if ($dbman->table_exists($table)) {
            //forum ratings only have a single time column so use it for both time created and modified
            $sql = "INSERT INTO {rating} (contextid, component, ratingarea, scaleid, itemid, rating, userid, timecreated, timemodified)

                    SELECT cxt.id, 'mod_forumplusone', 'post', f.scale, r.post AS itemid, r.rating, r.userid, r.time AS timecreated, r.time AS timemodified
                      FROM {forumplusone_ratings} r
                      JOIN {forumplusone_posts} p ON p.id=r.post
                      JOIN {forumplusone_discussions} d ON d.id=p.discussion
                      JOIN {forumplusone} f ON f.id=d.forum
                      JOIN {course_modules} cm ON cm.instance=f.id
                      JOIN {context} cxt ON cxt.instanceid=cm.id
                      JOIN {modules} m ON m.id=cm.module
                     WHERE m.name = :modname AND cxt.contextlevel = :contextlevel";
            $params['modname'] = 'forumplusone';
            $params['contextlevel'] = CONTEXT_MODULE;

            $DB->execute($sql, $params);

            //now drop forumplusone_ratings
            $dbman->drop_table($table);
        }

        upgrade_mod_savepoint(true, 2011112811, 'forumplusone');
    }

    if ($oldversion < 2011112812) {

        // Remove the forum digests message provider MDL-23145
        $DB->delete_records('message_providers', array('name' => 'digests','component'=>'mod_forumplusone'));

        // forum savepoint reached
        upgrade_mod_savepoint(true, 2011112812, 'forumplusone');
    }

    if ($oldversion < 2011112813) {
        // rename files from borked upgrade in 2.0dev
        $fs = get_file_storage();
        $rs = $DB->get_recordset('files', array('component'=>'mod_form'));
        foreach ($rs as $oldrecord) {
            $file = $fs->get_file_instance($oldrecord);
            $newrecord = array('component'=>'mod_forumplusone');
            if (!$fs->file_exists($oldrecord->contextid, 'mod_forumplusone', $oldrecord->filearea, $oldrecord->itemid, $oldrecord->filepath, $oldrecord->filename)) {
                $fs->create_file_from_storedfile($newrecord, $file);
            }
            $file->delete();
        }
        $rs->close();
        upgrade_mod_savepoint(true, 2011112813, 'forumplusone');
    }

    if ($oldversion < 2011112814) {
        // rating.component and rating.ratingarea have now been added as mandatory fields.
        // Presently you can only rate forum posts so component = 'mod_forumplusone' and ratingarea = 'post'
        // for all ratings with a forum context.
        // We want to update all ratings that belong to a forum context and don't already have a
        // component set.
        // This could take a while reset upgrade timeout to 5 min
        upgrade_set_timeout(60 * 20);
        $sql = "UPDATE {rating}
                SET component = 'mod_forumplusone', ratingarea = 'post'
                WHERE contextid IN (
                    SELECT ctx.id
                      FROM {context} ctx
                      JOIN {course_modules} cm ON cm.id = ctx.instanceid
                      JOIN {modules} m ON m.id = cm.module
                     WHERE ctx.contextlevel = 70 AND
                           m.name = 'forumplusone'
                ) AND component = 'unknown'";
        $DB->execute($sql);

        upgrade_mod_savepoint(true, 2011112814, 'forumplusone');
    }

    // Moodle v2.1.0 release upgrade line
    // Put any upgrade step following this

    // Moodle v2.2.0 release upgrade line
    // Put any upgrade step following this
    if ($oldversion < 2011112907) {
        /// Conditionally add field privatereply to be added to forumplusone
        $table = new xmldb_table('forumplusone_posts');
        $field = new xmldb_field('privatereply');
        $field->set_attributes(XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'flags');

        if(!$dbman->field_exists($table,$field)) {
            $dbman->add_field($table, $field);
        }

        // Define index privatereply (not unique) to be added to forumplusone_posts
        $table = new xmldb_table('forumplusone_posts');
        $index = new xmldb_index('privatereply', XMLDB_INDEX_NOTUNIQUE, array('privatereply'));

        // Conditionally launch add index privatereply
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Rename field forumplusoneid on table forumplusone_track_prefs to forumid
        $table = new xmldb_table('forumplusone_track_prefs');
        $field = new xmldb_field('forumplusoneid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0', 'userid');

        // Conditionally launch rename field forumplusoneid (if it exists)
        if($dbman->field_exists($table,$field)) {
            $dbman->rename_field($table, $field, 'forumid');
        }

        // forumplusone savepoint reached
        upgrade_mod_savepoint(true, 2011112907, 'forumplusone');
    }

    // Moodle v2.2.0 release upgrade line
    // Put any upgrade step following this

    // Moodle v2.3.0 release upgrade line
    // Put any upgrade step following this


    // Moodle v2.4.0 release upgrade line
    // Put any upgrade step following this

    // Forcefully assign mod/forumplusone:allowforcesubscribe to frontpage role, as we missed that when
    // capability was introduced.
    if ($oldversion < 2012112901) {
        // If capability mod/forumplusone:allowforcesubscribe is defined then set it for frontpage role.
        if (get_capability_info('mod/forumplusone:allowforcesubscribe')) {
            assign_legacy_capabilities('mod/forumplusone:allowforcesubscribe', array('frontpage' => CAP_ALLOW));
        }
        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2012112901, 'forumplusone');
    }

    if ($oldversion < 2012112902) {
        // Forum and forumplusone were reading from the same $CFG values, create new ones for forumplusone.

        $digestmailtime = 17; // Default in settings.php
        if (!empty($CFG->digestmailtime)) {
            $digestmailtime = $CFG->digestmailtime;
        }
        set_config('forumplusone_digestmailtime', $digestmailtime);

        $digestmailtimelast = 0;
        if (!empty($CFG->digestmailtimelast)) {
            $digestmailtimelast = $CFG->digestmailtimelast;
        }
        set_config('forumplusone_digestmailtimelast', $digestmailtimelast);

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2012112902, 'forumplusone');
    }

    if ($oldversion < 2013020500) {

        // Define field displaywordcount to be added to forum.
        $table = new xmldb_table('forumplusone');
        $field = new xmldb_field('displaywordcount', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'completionposts');

        // Conditionally launch add field displaywordcount.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2013020500, 'forumplusone');
    }

    // Moodle v2.5.0 release upgrade line.
    // Put any upgrade step following this.
    if ($oldversion < 2014021900) {
        // Define table forumplusone_digests to be created.
        $table = new xmldb_table('forumplusone_digests');

        // Adding fields to table forumplusone_digests.
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('forum', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('maildigest', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '-1');

        // Adding keys to table forumplusone_digests.
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $table->add_key('userid', XMLDB_KEY_FOREIGN, array('userid'), 'user', array('id'));
        $table->add_key('forum', XMLDB_KEY_FOREIGN, array('forum'), 'forum', array('id'));
        $table->add_key('forumdigest', XMLDB_KEY_UNIQUE, array('forum', 'userid', 'maildigest'));

        // Conditionally launch create table for forumplusone_digests.
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2014021900, 'forumplusone');
    }

    // Moodle v2.6.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2014040400) {

        // Define index userid-postid (not unique) to be dropped form forumplusone_read.
        $table = new xmldb_table('forumplusone_read');
        $index = new xmldb_index('userid-postid', XMLDB_INDEX_NOTUNIQUE, array('userid', 'postid'));

        // Conditionally launch drop index userid-postid.
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }


        // Define index postid-userid (not unique) to be added to forumplusone_read.
        $index = new xmldb_index('postid-userid', XMLDB_INDEX_NOTUNIQUE, array('postid', 'userid'));

        // Conditionally launch add index postid-userid.
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2014040400, 'forumplusone');
    }

    // Moodle v2.7.0 release upgrade line.
    // Put any upgrade step following this.

    if ($oldversion < 2014051201) {

        // Incorrect values that need to be replaced.
        $replacements = array(
            11 => 20,
            12 => 50,
            13 => 100
        );

        // Run the replacements.
        foreach ($replacements as $old => $new) {
            $DB->set_field('forumplusone', 'maxattachments', $new, array('maxattachments' => $old));
        }

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2014051201, 'forumplusone');
    }

    if ($oldversion < 2014051203) {
        // Find records with multiple userid/postid combinations and find the lowest ID.
        // Later we will remove all those which don't match this ID.
        $sql = "
            SELECT MIN(id) as lowid, userid, postid
            FROM {forumplusone_read}
            GROUP BY userid, postid
            HAVING COUNT(id) > 1";

        if ($duplicatedrows = $DB->get_recordset_sql($sql)) {
            foreach ($duplicatedrows as $row) {
                $DB->delete_records_select('forumplusone_read', 'userid = ? AND postid = ? AND id <> ?', array(
                    $row->userid,
                    $row->postid,
                    $row->lowid,
                ));
            }
        }
        $duplicatedrows->close();

        // Forum savepoint reached.
        upgrade_mod_savepoint(true, 2014051203, 'forumplusone');
    }

    if ($oldversion < 2014092400) {

        // Define fields to be added to forumplusone table.
        $table = new xmldb_table('forumplusone');
        $fields = array();
        $fields[] = new xmldb_field('showsubstantive', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'displaywordcount');
        $fields[] = new xmldb_field('showbookmark', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showsubstantive');

        // Go through each field and add if it doesn't already exist.
        foreach ($fields as $field){
            // Conditionally launch add field.
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2014092400, 'forumplusone');
    }

    if ($oldversion < 2014093000) {
        // Define fields to be added to forumplusone table.
        $table = new xmldb_table('forumplusone');
        $field = new xmldb_field('allowprivatereplies', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'showbookmark');

        // Conditionally launch add field.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2014093000, 'forumplusone');
    }

    if ($oldversion < 2014093001) {
        // Set default settings for existing forums.
        $DB->execute("
                UPDATE {forumplusone}
                   SET allowprivatereplies = 1,
                       showsubstantive = 1,
                       showbookmark = 1

        ");

        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2014093001, 'forumplusone');
    }


    // Convert global configs to plugin configs
    if ($oldversion < 2014100600) {
        $configs = array(
            'allowforcedreadtracking',
            'cleanreadtime',
            'digestmailtime',
            'digestmailtimelast',
            'disablebookmark',
            'disablesubstantive',
            'displaymode',
            'enablerssfeeds',
            'enabletimedposts',
            'lastreadclean',
            'longpost',
            'manydiscussions',
            'maxattachments',
            'maxbytes',
            'oldpostdays',
            'replytouser',
            'shortpost',
            'showbookmark',
            'showsubstantive',
            'trackingtype',
            'trackreadposts',
            'usermarksread'
        );

        // Migrate legacy configs to plugin configs.
        foreach ($configs as $config) {
            $oldvar = 'forumplusone_'.$config;
            if (isset($CFG->$oldvar)){
                // Set new config variable up based on legacy config.
                set_config($config, $CFG->$oldvar, 'forumplusone');
                // Delete legacy config.
                unset_config($oldvar);
            }
        }

        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2014100600, 'forumplusone');

    }

    if ($oldversion < 2014121700) {
        // Define fields to be added to forumplusone table.
        $table = new xmldb_table('forumplusone');
        $field = new xmldb_field('showrecent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'displaywordcount');

        // Conditionally launch add field.
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2014121700, 'forumplusone');
    }




    if ($oldversion < 2016042601) {
        // Define fields to be added to forumplusone table.
        $tableFI = new xmldb_table('forumplusone');
        $fieldEnable = new xmldb_field('enable_vote', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $fieldDisplaName = new xmldb_field('vote_display_name', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $fieldStart = new xmldb_field('votetimestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $fieldStop = new xmldb_field('votetimestop', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // Conditionally launch add field.
        if (!$dbman->field_exists($tableFI, $fieldEnable)) {
            $dbman->add_field($tableFI, $fieldEnable);
        }
        if (!$dbman->field_exists($tableFI, $fieldDisplaName)) {
            $dbman->add_field($tableFI, $fieldDisplaName);
        }
        if (!$dbman->field_exists($tableFI, $fieldStart)) {
            $dbman->add_field($tableFI, $fieldStart);
        }
        if (!$dbman->field_exists($tableFI, $fieldStop)) {
            $dbman->add_field($tableFI, $fieldStop);
        }




        $tableFI_Votes = new xmldb_table('forumplusone_vote');

        $tableFI_Votes->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $tableFI_Votes->add_field('postid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tableFI_Votes->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $tableFI_Votes->add_field('timestamp', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $tableFI_Votes->add_index('userid', XMLDB_INDEX_NOTUNIQUE, array('userid'));

        $tableFI_Votes->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));
        $tableFI_Votes->add_key('post_fk', XMLDB_KEY_FOREIGN, array('postid'), array('forumplusone_posts'), array('id'));


        if (!$dbman->table_exists($tableFI_Votes)) {
            $dbman->create_table($tableFI_Votes);
        }


        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2016042601, 'forumplusone');
    }


    if ($oldversion < 2016042702) {
        $tableFI = new xmldb_table('forumplusone');
        $fieldEnable = new xmldb_field('enable_close_disc', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');

        // Conditionally launch add field.
        if (!$dbman->field_exists($tableFI, $fieldEnable)) {
            $dbman->add_field($tableFI, $fieldEnable);
        }



        $tableFI_Disc = new xmldb_table('forumplusone_discussions');
        $fieldState = new xmldb_field('state', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        // Conditionally launch add field.
        if (!$dbman->field_exists($tableFI_Disc, $fieldState)) {
            $dbman->add_field($tableFI_Disc, $fieldState);
        }



        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2016042701, 'forumplusone');
    }


    if ($oldversion < 2016050301) {
        require_once($CFG->dirroot.'/mod/forumplusone/lib.php');

        $tableFI = new xmldb_table('forumplusone');
        $fieldCountMode = new xmldb_field('count_vote_mode', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, FORUMPLUSONE_COUNT_MODE_RECURSIVE);

        // Conditionally launch add field.
        if (!$dbman->field_exists($tableFI, $fieldCountMode)) {
            $dbman->add_field($tableFI, $fieldCountMode);
        }


        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2016050301, 'forumplusone');
    }


    if ($oldversion < 2016051900) {
        $tableFI = new xmldb_table('forumplusone');
        $fieldShowRecent = new xmldb_field('showrecent', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');

        // Conditionally launch add field.
        if (!$dbman->field_exists($tableFI, $fieldShowRecent)) {
            $dbman->change_field_default($tableFI, $fieldShowRecent);
        }


        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2016051900, 'forumplusone');
    }


    if ($oldversion < 2016052400) {
        $tableFI = new xmldb_table('forumplusone');
        $fieldEnable = new xmldb_field('enable_close_disc', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');

        $dbman->rename_field($tableFI, $fieldEnable, 'enable_states_disc');

        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2016052400, 'forumplusone');
    }


    if ($oldversion < 2016052600) {
        $tablePost = new xmldb_table('forumplusone_posts');
        $fieldSubject = new xmldb_field('subject', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);

        $dbman->drop_field($tablePost, $fieldSubject);

        // ForumPlusOne savepoint reached.
        upgrade_mod_savepoint(true, 2016052600, 'forumplusone');
    }


    return true;
}


