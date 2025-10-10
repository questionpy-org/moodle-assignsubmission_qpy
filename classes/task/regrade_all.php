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

namespace assignsubmission_qpy\task;

defined('MOODLE_INTERNAL') || die();

use core\context\module;
use assignsubmission_qpy\helper;

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Ad-hoc task to regrade all submissions for an assignment.
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regrade_all extends \core\task\adhoc_task {
    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $context = module::instance($data->cmid);
        $assignment = new \assign($context, null, null);
        $assignmentdefaultinstance = $assignment->get_default_instance();
        $questionid = helper::get_question_id($assignment);

        $newmaxscore = ($assignmentdefaultinstance->grade > 0) ? $assignmentdefaultinstance->grade : null;

        // Get all submissions for this assignment.
        $usageids = $DB->get_fieldset(
            'assignsubmission_qpy',
            'questionusageid',
            ['assignment' => $assignmentdefaultinstance->id],
        );

        $count = 0;
        foreach ($usageids as $usageid) {
            try {
                $quba = \question_engine::load_questions_usage_by_activity($usageid);
                $question = \question_bank::load_question($questionid);
                $quba->regrade_question($quba->get_first_question_number(), false, $newmaxscore, $question);
                \question_engine::save_questions_usage_by_activity($quba);
                $count++;
                mtrace("Regraded submission with questionusageid $usageid.");
            } catch (\Exception $e) {
                // Log the error but continue with other submissions.
                mtrace("Error regrading submission with questionusageid $usageid: " . $e->getMessage());
            }
        }

        mtrace("Regraded $count submissions for assignment with cmid {$data->cmid}.");
    }
}
