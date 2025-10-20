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

/**
 * This file contains the definition for the library class for the QuestionPy submission plugin.
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_qpy\event\assessable_uploaded;
use assignsubmission_qpy\event\submission_created;
use assignsubmission_qpy\event\submission_updated;
use assignsubmission_qpy\helper;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/questionpy/question.php');

use assignsubmission_qpy\qtype_questionpy\bridge;
use qtype_questionpy\constants;
use qtype_questionpy\local\files\response_file_service;
use qtype_questionpy\utils;

/**
 * Library class for QuestionPy submission plugin extending submission plugin base class
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_qpy extends assign_submission_plugin {
    /** @var string */
    private const RESPONSE_FILES_AREA = 'qpy_response_files';

    /** @var int */
    private const SUMMARY_MAX_ITEMS = 5;

    /**
     * Get the name of the qpy submission plugin.
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'assignsubmission_qpy');
    }

    /**
     * Get the default settings for this submission plugin.
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $DB;

        $defaultquestionid = helper::get_question_id($this->assignment) ?? 0;
        if ($this->assignment->has_instance()) {
            $defaultbehaviour = $this->get_config('preferredbehaviour');
        } else {
            $defaultbehaviour = 'deferredfeedback';
        }

        // Check if there are already submissions.
        $hassubmissions = false;
        if ($this->assignment->has_instance()) {
            $assignid = $this->assignment->get_instance()->id;
            $hassubmissions = $DB->record_exists('assignsubmission_qpy', ['assignment' => $assignid]);
        }

        if ($hassubmissions && $defaultquestionid > 0) {
            // If submissions exist, show a version selector instead of question ID field.
            $entryid = $DB->get_field(
                'question_versions',
                'questionbankentryid',
                ['questionid' => $defaultquestionid],
                MUST_EXIST
            );
            $versions = $DB->get_records(
                'question_versions',
                ['questionbankentryid' => $entryid],
                'version DESC',
                'questionid, version'
            );

            $versionoptions = [];
            foreach ($versions as $version) {
                $versionoptions[$version->questionid] = get_string('questionversion', 'assignsubmission_qpy', $version->version);
            }

            $mform->addElement(
                'select',
                'assignsubmission_qpy_questionid',
                get_string('questionversion_select', 'assignsubmission_qpy'),
                $versionoptions
            );
            $mform->setDefault('assignsubmission_qpy_questionid', $defaultquestionid);
            $mform->hideIf('assignsubmission_qpy_questionid', 'assignsubmission_qpy_enabled', 'notchecked');

            // Add checkbox to regrade all submissions.
            $mform->addElement(
                'advcheckbox',
                'assignsubmission_qpy_regradeall',
                get_string('regradeall', 'assignsubmission_qpy')
            );
            $mform->addHelpButton('assignsubmission_qpy_regradeall', 'regradeall', 'assignsubmission_qpy');
            $mform->hideIf('assignsubmission_qpy_regradeall', 'assignsubmission_qpy_enabled', 'notchecked');
        } else {
            // No submissions yet, show the question ID text field.
            // TODO: We should add a selector here with all questions in course question banks.
            $mform->addElement('text', 'assignsubmission_qpy_questionid', 'Question ID');
            $mform->setDefault('assignsubmission_qpy_questionid', $defaultquestionid);
            $mform->setType('assignsubmission_qpy_questionid', PARAM_INT);
            $mform->hideIf('assignsubmission_qpy_questionid', 'assignsubmission_qpy_enabled', 'notchecked');
        }

        $behaviours = question_engine::get_behaviour_options($defaultbehaviour);
        $mform->addElement(
            'select',
            'assignsubmission_qpy_preferredbehaviour',
            get_string('howquestionsbehave', 'question'),
            $behaviours,
        );
        $mform->setDefault('assignsubmission_qpy_preferredbehaviour', $defaultbehaviour);
        $mform->addHelpButton('assignsubmission_qpy_preferredbehaviour', 'howquestionsbehave', 'question');
        $mform->hideIf('assignsubmission_qpy_preferredbehaviour', 'assignsubmission_qpy_enabled', 'notchecked');
    }

    /**
     * This method is called when the mod_assign_mod_form is submitted.
     *
     * It is an opportunity to validate the settings form fields added by {@see get_settings()}.
     *
     * @param array $data as passed to mod_assign_mod_form::validation().
     * @param array $files as passed to mod_assign_mod_form::validation().
     * @return array and validation errors that should be displayed.
     *      This is array_merged with any other validation errors from the form.
     */
    public function settings_validation(array $data, array $files): array {
        global $DB;

        // This should be handled by Moodle, but it is not.
        if (empty($data['assignsubmission_qpy_enabled'])) {
            return [];
        }

        $errors = [];
        $questionid = $data['assignsubmission_qpy_questionid'];

        // Check that the question exists.
        if (!$DB->record_exists('question_versions', ['questionid' => $questionid])) {
            $errors['assignsubmission_qpy_questionid'] = get_string('questionnotfound', 'assignsubmission_qpy');
            return $errors;
        }

        // User must be allowed to view/use the question. In addition, we also require the question to be in the same
        // course as this assign activity. Moodle supports using questions from other courses,
        // but this requirement is safer for us (in case we forgot some additional checks).
        $question = question_bank::load_question($questionid);
        $questioncoursecontext = context::instance_by_id($question->contextid)->get_course_context();
        $assigncoursecontext = $this->assignment->get_course_context();
        if (
            $questioncoursecontext->id != $assigncoursecontext->id ||
            !question_has_capability_on($question, 'use') ||
            !question_has_capability_on($question, 'view')
        ) {
            $errors['assignsubmission_qpy_questionid'] = get_string('questionnopermission', 'assignsubmission_qpy');
        } else if (!$question instanceof \qtype_questionpy_question) {
            // Only QuestionPy questions are allowed.
            $errors['assignsubmission_qpy_questionid'] = get_string('questionpyrequired', 'assignsubmission_qpy');
        }

        // Check grade type to be point.
        if ($data['grade'] <= 0) {
            $errors['grade'] = get_string('grademustbepoint', 'assignsubmission_qpy');
        }

        return $errors;
    }

    /**
     * Save the settings for this submission plugin.
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        global $DB;

        $this->set_config('preferredbehaviour', $data->assignsubmission_qpy_preferredbehaviour);

        // Write entry to question_references.
        $qv = $DB->get_record('question_versions', ['questionid' => $data->assignsubmission_qpy_questionid]);
        $contextid = $this->assignment->get_context()->id;
        $assigninstance = $this->assignment->get_instance();
        $ref = $DB->get_record('question_references', [
            'usingcontextid' => $contextid,
            'component' => 'assignsubmission_qpy',
            'questionarea' => 'main',
            'itemid' => $assigninstance->id,
        ]);
        if ($ref === false) {
            $ref = new stdClass();
            $ref->usingcontextid = $contextid;
            $ref->component = 'assignsubmission_qpy';
            $ref->questionarea = 'main';
            $ref->itemid = $assigninstance->id;
            $ref->questionbankentryid = $qv->questionbankentryid;
            $ref->version = $qv->version;
            $DB->insert_record('question_references', $ref);
        } else {
            $updateref = new stdClass();
            $updateref->id = $ref->id;
            $updateref->questionbankentryid = $qv->questionbankentryid;
            $updateref->version = $qv->version;
            $DB->update_record('question_references', $updateref);
        }

        // Schedule regrade task if checkbox was checked.
        if (!empty($data->assignsubmission_qpy_regradeall)) {
            $task = new \assignsubmission_qpy\task\regrade_all();
            $task->set_custom_data([
                'cmid' => $this->assignment->get_course_module()->id,
            ]);
            \core\task\manager::queue_adhoc_task($task, true);
        }

        return true;
    }

    /**
     * Get any additional fields for the submission/grading form for this assignment.
     *
     * @param mixed $submission submission|grade - For submission plugins this is the submission data,
     *                                                    for feedback plugins it is the grade data
     * @param MoodleQuickForm $mform - This is the form
     * @param stdClass $data - This is the form data that can be modified for example by a filemanager element
     * @param int $userid - This is the userid for the current submission.
     *                      This is passed separately as there may not yet be a submission or grade.
     * @return boolean - true if we added anything to the form
     */
    public function get_form_elements_for_user($submission, MoodleQuickForm $mform, stdClass $data, $userid) {
        if ($submission === null) {
            $mform->addElement('html', get_string('gotnosubmission', 'assignsubmission_qpy'));
            return true;
        }

        $quba = $this->get_question_usage($submission, false);
        if (!$quba) {
            $quba = $this->create_question_usage_attempt($submission);
        }

        $displayoptions = helper::get_question_display_options(
            $this->assignment,
            $submission,
            review: false,
            mayshowhistory: false,
        );
        $questionhtml = $quba->render_question($quba->get_first_question_number(), $displayoptions);
        $mform->addElement('html', $questionhtml);
        return true;
    }

    /**
     * Create a question usage for the current user and add the question.
     *
     * @param stdClass $submission
     * @param question_usage_by_activity|null $basedon
     * @return question_usage_by_activity
     * @throws moodle_exception
     */
    private function create_question_usage_attempt(
        stdClass $submission,
        ?question_usage_by_activity $basedon = null
    ): question_usage_by_activity {
        global $DB;

        // Get the question.
        $questionid = helper::get_question_id($this->assignment);
        if ($questionid === null) {
            throw new moodle_exception('questionnotfound', 'assignsubmission_qpy');
        }
        $question = question_bank::load_question($questionid);

        // Create a new question usage.
        $quba = question_engine::make_questions_usage_by_activity('assignsubmission_qpy', $this->assignment->get_context());
        $quba->set_preferred_behaviour($this->get_config('preferredbehaviour'));

        $quba->add_question($question);
        $this->set_qpy_bridge($quba, $submission);
        if ($basedon) {
            $oldqa = $basedon->get_question_attempt($basedon->get_first_question_number());
            $quba->start_question_based_on($quba->get_first_question_number(), $oldqa);
        } else {
            $quba->start_all_questions();
        }

        $transaction = $DB->start_delegated_transaction();

        // Save the question usage.
        question_engine::save_questions_usage_by_activity($quba);

        // Save usageid in our table.
        $qpysubmission = new stdClass();
        $qpysubmission->assignment = $this->assignment->get_instance()->id;
        $qpysubmission->submission = $submission->id;
        $qpysubmission->questionusageid = $quba->get_id();
        $DB->insert_record('assignsubmission_qpy', $qpysubmission);

        $transaction->allow_commit();
        return $quba;
    }

    /**
     * Get the {@see question_usage_by_activity} for the submission.
     *
     * @param mixed $submission
     * @param bool $mustexist
     * @return question_usage_by_activity|null
     */
    private function get_question_usage($submission, bool $mustexist = true): ?question_usage_by_activity {
        global $DB;
        $questionusageid = $DB->get_field('assignsubmission_qpy', 'questionusageid', ['submission' => $submission->id]);
        if ($questionusageid === false) {
            if ($mustexist) {
                throw new \moodle_exception('submissionnotfound', 'assignsubmission_qpy');
            }
            return null;
        }
        $quba = question_engine::load_questions_usage_by_activity($questionusageid);
        $this->set_qpy_bridge($quba, $submission);
        return $quba;
    }

    /**
     * Set the QuestionPy bridge to this plugin.
     *
     * @param question_usage_by_activity $quba
     * @param stdClass $submission
     * @return void
     */
    private function set_qpy_bridge(question_usage_by_activity $quba, \stdClass $submission): void {
        $attempt = $quba->get_question_attempt($quba->get_first_question_number());
        $question = $attempt->get_question(false);
        if ($question instanceof \qtype_questionpy_question) {
            $bridge = bridge::create_from_submission($attempt, $this->assignment->get_context(), $submission);
            $question->set_bridge($bridge);
        }
    }

    /**
     * Determine if a submission is empty.
     *
     * This is distinct from is_empty in that it is intended to be used to
     * determine if a submission made before saving is empty.
     *
     * @param stdClass $data The submission data
     * @return bool
     */
    public function submission_is_empty(stdClass $data) {
        // Returning true would block saving the answer.
        return false;
    }

    /**
     * Return true if there are no submission files.
     *
     * @param stdClass $submission
     */
    public function is_empty(stdClass $submission) {
        // This is called after `$this->save()` by \assign::save_submission().
        return false;
    }

    /**
     * Save all data for this submission.
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     * @throws moodle_exception
     */
    public function save(stdClass $submission, stdClass $data) {
        global $DB, $USER;

        $params = [
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'objectid' => $submission->id,
            'other' => [
                'content' => '',
                'pathnamehashes' => [],
            ],
        ];

        if (!empty($submission->userid) && ($submission->userid != $USER->id)) {
            $params['relateduserid'] = $submission->userid;
        }

        $event = assessable_uploaded::create($params);
        $event->trigger();

        // Get the group name as other fields are not transcribed in the logs and this information is important.
        if (empty($submission->userid) && !empty($submission->groupid)) {
            $groupname = $DB->get_field('groups', 'name', ['id' => $submission->groupid], MUST_EXIST);
            $groupid = $submission->groupid;
        } else {
            $params['relateduserid'] = $submission->userid;
        }

        // Unset the 'other' and 'objectid' field from params for use in submission events.
        unset($params['other'], $params['objectid']);

        $params['other'] = [
            'submissionid' => $submission->id,
            'submissionattempt' => $submission->attemptnumber,
            'submissionstatus' => $submission->status,
            'groupid' => $groupid ?? 0,
            'groupname' => $groupname ?? null,
        ];

        // Save the question usage.
        $quba = $this->get_question_usage($submission);
        $quba->process_all_actions();
        question_engine::save_questions_usage_by_activity($quba);

        // Copy the latest submitted files (if any) to our file area.

        $fs = get_file_storage();
        // Instead of smartly handling existing files, we just delete them all and re-add them.
        $fs->delete_area_files(
            $this->assignment->get_context()->id,
            'assignsubmission_qpy',
            self::RESPONSE_FILES_AREA,
            $submission->id
        );

        $attempt = $quba->get_question_attempt($quba->get_first_question_number());
        // Get_last_qt_files doesn't work on pending steps, so we use the question_files_saver/_loader.
        $filesaver = $attempt->get_last_qt_var(constants::QT_VAR_RESPONSE_FILES);
        if (!$filesaver) {
            // Looks like no files were submitted.
            $files = [];
        } else if (!($filesaver instanceof question_response_files)) {
            debugging('Unexpected type of qt var ' . constants::QT_VAR_RESPONSE_FILES . ': ' . gettype($filesaver));
            $files = [];
        } else {
            $files = $filesaver->get_files();
        }

        /** @var stored_file $file */
        foreach ($files as $file) {
            [$fieldname, $filename] = response_file_service::unmangle_filename($file->get_filename());

            $fs->create_file_from_storedfile([
                'component' => 'assignsubmission_qpy',
                'filearea' => self::RESPONSE_FILES_AREA,
                'itemid' => $submission->id,
                'contextid' => $this->assignment->get_context()->id,
                'filepath' => '/' . $fieldname . '/',
                'filename' => $filename,
            ], $file);
        }

        // Trigger created- or updated-event.
        $qpysubmission = $DB->get_record('assignsubmission_qpy', ['submission' => $submission->id], 'id, issaved', MUST_EXIST);
        $params['objectid'] = $qpysubmission->id;

        if ($qpysubmission->issaved == 0) {
            $event = submission_created::create($params);
            $DB->set_field('assignsubmission_qpy', 'issaved', '1');
        } else {
            $event = submission_updated::create($params);
        }
        $event->set_assign($this->assignment);
        $event->trigger();

        return true;
    }

    /**
     * Information to be displayed about the submission.
     *
     * This is displayed on multiple pages and should actually only show a summary.
     *
     * @param stdClass $submission
     * @param bool $showviewlink
     * @return string
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function view_summary(stdClass $submission, &$showviewlink): string {
        // The grading page has a long table. Do not display the full submission.
        if (str_ends_with($_SERVER['SCRIPT_NAME'], 'view.php') && optional_param('action', '', PARAM_ALPHANUMEXT) === 'grading') {
            $showviewlink = true; // Always show the link to view the attempt and history.
            $quba = $this->get_question_usage($submission);
            $attempt = $quba->get_question_attempt($quba->get_first_question_number());
            $response = utils::get_qpy_response($attempt);

            $fs = get_file_storage();
            $files = $fs->get_area_files(
                $this->assignment->get_context()->id,
                'assignsubmission_qpy',
                self::RESPONSE_FILES_AREA,
                $submission->id,
                includedirs: false
            );
            if ($response === null && !$files) {
                return get_string('noqpyresponse', 'assignsubmission_qpy');
            }

            if (isset($response->data) && !(array)$response->data) {
                // Showing the dynamic JS data when it isn't used would just be confusing.
                unset($response->data);
            }

            if (count($files) + count((array) $response) > self::SUMMARY_MAX_ITEMS) {
                return get_string('summarytoomanyitems', 'assignsubmission_qpy', a: [
                    'responsefieldcount' => count((array) $response),
                    'filecount' => count($files),
                ]);
            }

            $html = '';

            $listitems = [];
            foreach ($response as $responsekey => $responsevalue) {
                assert(is_string($responsevalue));
                $listitems[] = get_string('summaryresponsestring', 'assignsubmission_qpy', [
                    'key' => s($responsekey),
                    'value' => s($responsevalue),
                ]);
            }
            if ($listitems) {
                $html .= html_writer::alist($listitems, ['class' => 'm-1 list-unstyled']);
            }

            if ($files) {
                $html .= get_string('summaryresponsefiles', 'assignsubmission_qpy', a: count($files));
                $html .= $this->assignment->render_area_files('assignsubmission_qpy', self::RESPONSE_FILES_AREA, $submission->id);
            }

            return $html;
        }

        return $this->view($submission, false);
    }

    /**
     * Full submission view.
     *
     * @param stdClass $submission
     * @param bool $mayshowhistory
     * @return string
     */
    public function view(stdClass $submission, bool $mayshowhistory = true) {
        $quba = $this->get_question_usage($submission, false);
        if ($quba === null) {
            return '';
        }

        $displayoptions = helper::get_question_display_options(
            $this->assignment,
            $submission,
            review: true,
            mayshowhistory: true
        );
        $quba->preload_all_step_users();
        return $quba->render_question($quba->get_first_question_number(), $displayoptions);
    }

    /**
     * Check if the submission plugin has all the required data to allow the work
     * to be submitted for grading.
     *
     * @param stdClass $submission the assign_submission record being submitted.
     * @return bool|string 'true' if OK to proceed with submission, otherwise a
     *                        a message to display to the user
     */
    public function precheck_submission($submission) {
        try {
            $quba = $this->get_question_usage($submission);
        } catch (\moodle_exception $e) {
            return $e->getMessage();
        }
        $attempt = $quba->get_question_attempt($quba->get_first_question_number());
        $response = $attempt->get_last_qt_data();
        $question = $attempt->get_question();
        if (!$question->is_gradable_response($response)) {
            return get_string('questionnotgradable', 'assignsubmission_qpy');
        }

        return true;
    }

    /**
     * Carry out any extra processing required when the work is submitted for grading.
     *
     * @param stdClass $submission the assign_submission record being submitted.
     * @return void
     */
    public function submit_for_grading($submission) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $quba = $this->get_question_usage($submission);
        $quba->finish_all_questions();
        question_engine::save_questions_usage_by_activity($quba);
        $transaction->allow_commit();
    }

    /**
     * Carry out any extra processing required when the work reverted to draft.
     *
     * @param stdClass $submission - assign_submission data
     * @return void
     */
    public function revert_to_draft(stdClass $submission) {
        // TODO: We could introduce a QPy behaviour action 'reopen' or something to put the question attempt in
        // the todo state again. Care must be taken to ensure that users cannot infiltrate this action.
    }

    /**
     * Copy the student's submission from a previous submission.
     *
     * Used when a student opts to base their resubmission on the last submission.
     * @param stdClass $oldsubmission - Old submission record
     * @param stdClass $submission - New submission record
     * @return bool
     */
    public function copy_submission(stdClass $oldsubmission, stdClass $submission) {
        // If there is already a new qpy submission, delete it first.
        $this->remove($submission);

        // Create a new question usage based on the old one.
        $oldqaba = $this->get_question_usage($oldsubmission, false);
        if ($oldqaba) {
            $this->create_question_usage_attempt($submission, $oldqaba);
        }
        return true;
    }

    /**
     * Produce a list of files suitable for export that represent this feedback or submission
     *
     * @param stdClass $submission assign_submission or assign_grade
     *                 For submission plugins this is the submission data, for feedback plugins it is the grade data
     * @param stdClass $user The user record for the current submission.
     *                         Needed for url rewriting if this is a group submission.
     * @return array - return an array of files indexed by filename
     * @throws coding_exception
     */
    public function get_files(stdClass $submission, stdClass $user): array {
        // Mostly stolen from assign_submission_file.
        $fs = get_file_storage();

        $files = $fs->get_area_files(
            $this->assignment->get_context()->id,
            'assignsubmission_qpy',
            self::RESPONSE_FILES_AREA,
            $submission->id,
            'timemodified, id',
            false
        );

        $result = [];
        foreach ($files as $file) {
            $result[$file->get_filepath() . $file->get_filename()] = $file;
        }
        return $result;
    }

    /**
     * Get file areas returns a list of areas this plugin stores files.
     *
     * @return array - An array of fileareas (keys) and descriptions (values)
     */
    public function get_file_areas() {
        return [self::RESPONSE_FILES_AREA => get_string('attemptfiles', 'assignsubmission_qpy')];
    }

    /**
     * Remove any saved data from this submission.
     *
     * @param stdClass $submission - assign_submission data
     * @return void
     */
    public function remove(stdClass $submission) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $qubaid = $DB->get_field('assignsubmission_qpy', 'questionusageid', ['submission' => $submission->id]);
        if ($qubaid !== false) {
            question_engine::delete_questions_usage_by_activity($qubaid);
            $DB->delete_records('assignsubmission_qpy', ['submission' => $submission->id]);
        }

        $transaction->allow_commit();
    }

    /**
     * The assignment has been deleted - remove the plugin specific data.
     *
     * Is also called by reset_userdata.
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $assignid = $this->assignment->get_instance()->id;

        // This method is called both when the whole assignment is getting deleted, but also on reset_userdata.
        // Only when the whole assignment is getting deleted, we should delete our entry in question_references.
        // This is very hacky.
        $callingfunction = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
        if ($callingfunction !== 'reset_userdata') {
            if ($callingfunction !== 'delete_instance') {
                debugging('Calling function is not delete_instance or reset_userdata');
            }
            $DB->delete_records('question_references', [
                'usingcontextid' => $this->assignment->get_context()->id,
                'component' => 'assignsubmission_qpy',
                'questionarea' => 'main',
                'itemid' => $assignid,
            ]);
        }

        $qubas = new \qubaid_join(
            '{assignsubmission_qpy} asqpy',
            'asqpy.questionusageid',
            'asqpy.assignment = :assignmentid',
            ['assignmentid' => $assignid],
        );
        question_engine::delete_questions_usage_by_activities($qubas);
        $DB->delete_records('assignsubmission_qpy', ['assignment' => $assignid]);

        $transaction->allow_commit();
        return true;
    }

    /**
     * Summarise a submission for inclusion in messages.
     *
     * Moodle messages can be sent as either HTML or plain text, so you need to
     * produce two versions of the summary.
     *
     * If there is nothing in the submission from your plugin return an array of two empty strings.
     *
     * The plain text version should finish in a newline character.
     * The HTML version should have block-level elements like headings or <p>s as the outer elements.
     *
     * @param stdClass $submission the assign_submission record for the submission the message is about.
     * @return string[] with two elements, a plain text summary and an HTML summary.
     */
    public function submission_summary_for_messages(stdClass $submission): array {
        // TODO.
        return ['', ''];
    }
}
