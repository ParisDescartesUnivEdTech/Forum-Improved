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
 * Repository Mapper Abstract
 *
 * @package    mod
 * @subpackage forumplusone
 * @copyright  Copyright (c) 2012 Moodlerooms Inc. (http://www.moodlerooms.com)
 * @copyright Copyright (c) 2016 Paris Descartes University (http://www.univ-paris5.fr/)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class forumplusone_repository_abstract {
    /**
     * @var moodle_database
     */
    protected $db;

    /**
     * @param moodle_database|null $db
     */
    public function __construct(moodle_database $db = null) {
        global $DB;

        if (is_null($db)) {
            $this->db = $DB;
        } else {
            $this->db = $db;
        }
    }

    /**
     * @param \moodle_database $db
     * @return forumplusone_repository_discussion
     */
    public function set_db($db) {
        $this->db = $db;
        return $this;
    }

    /**
     * @return \moodle_database
     */
    public function get_db() {
        return $this->db;
    }
}
