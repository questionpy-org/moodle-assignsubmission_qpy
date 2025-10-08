<?php
// This file is part of the QuestionPy Moodle plugin - https://questionpy.org
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

namespace assignsubmission_qpy\qtype_questionpy;

defined('MOODLE_INTERNAL') || die();

use core\context;

require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Bridge class between QuestionPy and assignsubmission_qpy.
 *
 * This class is used to retrieve data that is not available through the Question API.
 *
 * @package    assignsubmission_qpy
 * @author     Martin Gauk
 * @copyright  2025 TU Berlin, innoCampus {@link https://www.questionpy.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bridge extends \qtype_questionpy\question_bridge_base {
    /** @var \stdClass|null data from assign_submission table. */
    private $submissiondata = null;

    /**
     * Get additional LMS attributes.
     *
     * @param string[] $requestedattributes
     * @return string[]
     */
    protected function get_additional_lms_attributes(array $requestedattributes): array {
        $attributes = [];

        if (in_array('attempt_started_at', $requestedattributes)) {
            $attributes['attempt_started_at'] = ($this->attempt->get_num_steps()) ?
                date('c', $this->attempt->get_step(0)->get_timecreated()) : date('c');
        }

        if (in_array('submission_at', $requestedattributes)) {
            $submission = $this->get_submission_data();
            $attributes['submission_at'] =
                ($submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED && $submission->timemodified) ?
                    date('c', $submission->timemodified) : null;
        }

        if (in_array('lms_moodle_component_name', $requestedattributes)) {
            $attributes['lms_moodle_component_name'] = 'assignsubmission_qpy';
        }

        if (in_array('lms_moodle_module_instance', $requestedattributes)) {
            $submission = $this->get_submission_data();
            $attributes['lms_moodle_module_instance'] = (int) $submission->assignment;
        }

        if (in_array('lms_moodle_cmid', $requestedattributes)) {
            $attributes['lms_moodle_cmid'] = $this->context->instanceid;
        }

        return $attributes;
    }

    /**
     * Get user or group id this attempt belongs to.
     *
     * @return array<'user'|'group', int>
     */
    public function get_user_or_group_id(): array {
        $submission = $this->get_submission_data();
        if ($submission->userid) {
            return ['user', $submission->userid];
        } else {
            return ['group', $submission->groupid];
        }
    }

    /**
     * Getter to lazily load the submission data from database.
     *
     * @return \stdClass
     */
    private function get_submission_data(): \stdClass {
        global $DB;

        if ($this->submissiondata === null) {
            $usageid = $this->attempt->get_usage_id();
            $sql = 'SELECT s.*
                    FROM {assignsubmission_qpy} q
                    JOIN {assign_submission} s ON (s.id = q.submission)
                    WHERE q.questionusageid = :qubaid';
            $this->submissiondata = $DB->get_record_sql($sql, ['qubaid' => $usageid], MUST_EXIST);
        }
        return $this->submissiondata;
    }

    /**
     * Create a new instance of this class from a submission without having to fetch data from the database.
     *
     * @param \question_attempt $attempt
     * @param context $context
     * @param \stdClass $submissiondata
     * @return self
     */
    public static function create_from_submission(\question_attempt $attempt, context $context,
                                                  \stdClass $submissiondata): self {
        $instance = new self($attempt, $context);
        $instance->submissiondata = $submissiondata;
        return $instance;
    }
}
