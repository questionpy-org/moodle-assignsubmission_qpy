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
}
