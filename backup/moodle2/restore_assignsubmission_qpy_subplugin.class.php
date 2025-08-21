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
 * This file contains the class for restore of this submission plugin.
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restore subplugin class.
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignsubmission_qpy_subplugin extends restore_subplugin {
    use restore_questions_attempt_data_trait;

    /** @var stdClass|null $currentsubmission Track the current submission being restored. */
    protected $currentsubmission = null;

    /**
     * Returns the paths to be handled by the subplugin at assign level.
     *
     * @return array
     */
    protected function define_assign_subplugin_structure() {
        $paths = [];

        $elename = $this->get_namefor('question_reference');
        $elepath = $this->get_pathfor('/question_references/question_reference');
        // We used get_recommended_name() so this works.
        $paths[] = new restore_path_element($elename, $elepath);

        // The trait restore_question_reference_data_trait has a hard-coded dependency to mod_quiz.
        // It expects a quiz_question_instance. That is why we process the data by ourselves.

        return $paths;
    }

    /**
     * Returns the paths to be handled by the subplugin at submission level.
     *
     * @return array
     */
    protected function define_submission_subplugin_structure() {
        $paths = [];

        $elename = $this->get_namefor('submission');
        $elepath = $this->get_pathfor('/submission_qpy');
        // We used get_recommended_name() so this works.
        $qpysubmission = new restore_path_element($elename, $elepath);
        $paths[] = $qpysubmission;

        $this->add_question_usages($qpysubmission, $paths);

        return $paths;
    }

    /**
     * Processes the question_reference element.
     *
     * @param mixed $data
     * @return void
     */
    public function process_assignsubmission_qpy_question_reference($data) {
        global $DB;
        $data = (object) $data;
        $data->usingcontextid = $this->get_mappingid('context', $data->usingcontextid);
        $data->itemid = $this->get_new_parentid('assign');
        if ($entry = $this->get_mappingid('question_bank_entry', $data->questionbankentryid)) {
            $data->questionbankentryid = $entry;
        }
        $DB->insert_record('question_references', $data);
    }

    /**
     * Processes one submission_qpy element.
     *
     * @param mixed $data
     * @return void
     */
    public function process_assignsubmission_qpy_submission($data) {
        if ($this->currentsubmission) {
            throw new coding_exception('currentsubmission must be null');
        }

        $data = (object) $data;
        $data->assignment = $this->get_new_parentid('assign');
        // The mapping is set in the restore for the core assign activity
        // when a submission node is processed.
        $data->submission = $this->get_mappingid('submission', $data->submission);

        // Data will be inserted by inform_new_usage_id.
        $this->currentsubmission = clone($data);
    }

    /**
     * A new question usage was created.
     *
     * When the restore_questions_attempt_data_trait trait creates the new usage, it calls this method
     * to let the activity link to the new usage.
     *
     * @param integer $newusageid
     */
    protected function inform_new_usage_id($newusageid) {echo "inform_new_usage_id called\n";
        global $DB;

        if (!$this->currentsubmission) {
            throw new coding_exception('currentsubmission must not be null');
        }

        $this->currentsubmission->questionusageid = $newusageid;
        $DB->insert_record('assignsubmission_qpy', $this->currentsubmission);
        $this->currentsubmission = null;
    }

    /**
     * Dummy method.
     *
     * \restore_questions_attempt_data_trait::add_question_usages defines a path for question_attempt_step_data,
     * although this path is already handled by the method process_question_attempt_step. For some reason,
     * it is expected for this plugin to define process_question_attempt_step_data.
     * I don't know why this error doesn't happen in mod_quiz.
     */
    public function process_question_attempt_step_data($data) {
        throw new coding_exception('process_question_attempt_step_data should never be called');
    }

    /**
     * Get the task we are part of.
     *
     * Needed by \restore_questions_attempt_data_trait::get_qtype_restorer to instantiate the question's restore class.
     *
     * @return restore_task
     */
    public function get_task() {
        return $this->task;
    }
}
