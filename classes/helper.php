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

namespace assignsubmission_qpy;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/questionlib.php');

use core\context;
use question_display_options;

/**
 * Helper methods for the mod_assign QuestionPy submission plugin.
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Get the question id for an assignment instance.
     *
     * @param \assign $assignment
     * @return int|null
     */
    public static function get_question_id(\assign $assignment): ?int {
        global $DB;

        if (!$assignment->has_instance()) {
            return null;
        }

        $id = $DB->get_field_sql("
            SELECT qv.questionid
              FROM {question_references} qr
              JOIN {question_versions} qv ON
                       (qv.questionbankentryid = qr.questionbankentryid AND qv.version = qr.version)
             WHERE qr.usingcontextid = :context AND qr.component = 'assignsubmission_qpy'
                       AND qr.questionarea = 'main' AND qr.itemid = :itemid", [
            'context' => $assignment->get_context()->id,
            'itemid' => $assignment->get_default_instance()->id,
        ]);

        return ($id !== false) ? intval($id) : null;
    }

    /**
     * Get the question display options for a submission.
     *
     * @param \assign $assignment
     * @param \stdClass $submission
     * @param bool $review read-only/review mode
     * @param bool $mayshowhistory display response history if the user has the capability
     * @return question_display_options
     */
    public static function get_question_display_options(
        \assign $assignment,
        \stdClass $submission,
        bool $review,
        bool $mayshowhistory,
    ): question_display_options {
        $context = $assignment->get_context();

        $displayoptions = new question_display_options();
        $displayoptions->readonly = $review;

        // Setting $marks works, $correctness gets overwritten / ignored by qbehaviour_adaptive, but we hide it in CSS.
        $displayoptions->marks = question_display_options::HIDDEN;
        $displayoptions->correctness = question_display_options::HIDDEN;
        $displayoptions->flags = question_display_options::HIDDEN;
        $displayoptions->history = question_display_options::HIDDEN;

        if ($mayshowhistory && has_capability('mod/assign:grade', $context)) {
            $displayoptions->history = question_display_options::VISIBLE;

            // The attribute userinfoinhistory is either question_display_options::HIDDEN or the id of the user
            // who owns the question attempt. Moodle only displays the name of the user to whom an attempt step
            // belongs if their ID is different to userinfoinhistory.
            if ($assignment->is_blind_marking() && !has_capability('mod/assign:viewblinddetails', $context)) {
                $displayoptions->userinfoinhistory = question_display_options::HIDDEN;
            } else if ($assignment->get_instance()->teamsubmission) {
                $displayoptions->userinfoinhistory = 1; // Display all names (as 1 is the guest user id).
            } else {
                $displayoptions->userinfoinhistory = $submission->userid; // Only display a name if different from this user.
            }
        }

        return $displayoptions;
    }

    /**
     * Get the assignment object for a submission and check access permissions for the current user.
     *
     * @param context $context
     * @param \stdClass|null $cm
     * @param \stdClass $course
     * @param \stdClass $submission
     * @return \assign
     */
    public static function get_assignment_and_check_access(
        context $context,
        ?\stdClass $cm,
        \stdClass $course,
        \stdClass $submission
    ): \assign {
        if ($context->contextlevel != CONTEXT_MODULE) {
            throw new \moodle_exception('invalidcourselevel', 'error');
        }

        if (!$cm) {
            [, $cm] = get_course_and_cm_from_cmid($context->instanceid, 'assign', $course);
        }

        require_login($course, false, $cm);
        $assign = new \assign($context, $cm, $course);

        // Verify that the submission belongs to this assignment activity.
        if ($assign->get_instance()->id != $submission->assignment) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        // Check team submission permissions.
        if (
            $assign->get_instance()->teamsubmission &&
            !$assign->can_view_group_submission($submission->groupid)
        ) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        // Check individual submission permissions.
        if (
            !$assign->get_instance()->teamsubmission &&
            !$assign->can_view_submission($submission->userid)
        ) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        return $assign;
    }
}
