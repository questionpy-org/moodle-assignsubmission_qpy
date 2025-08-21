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
 * This file contains the class for backup of this submission plugin.
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Provides the information to backup the submission.
 *
 * @package    assignsubmission_qpy
 * @copyright  2025 Martin Gauk, TU Berlin, innoCampus - www.questionpy.org
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_assignsubmission_qpy_subplugin extends backup_subplugin {
    use backup_questions_attempt_data_trait;
    use backup_question_reference_data_trait;

    /**
     * Returns the subplugin information to attach to assign element.
     *
     * @return backup_subplugin_element
     */
    protected function define_assign_subplugin_structure() {

        // Create XML elements.
        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());
        $questionrefs = new backup_nested_element('question_references');

        // Connect XML elements into the tree.
        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($questionrefs);

        $this->add_question_references($questionrefs, 'assignsubmission_qpy', 'main');
        $this->annotate_question_categories();

        return $subplugin;
    }

    /**
     * Returns the subplugin information to attach to submission element.
     *
     * @return backup_subplugin_element
     */
    protected function define_submission_subplugin_structure() {

        // Create XML elements.
        $subplugin = $this->get_subplugin_element();
        $subpluginwrapper = new backup_nested_element($this->get_recommended_name());
        $subpluginelement = new backup_nested_element('submission_qpy',
                                                      null,
                                                      ['submission', 'questionusageid']);

        // Connect XML elements into the tree.
        $subplugin->add_child($subpluginwrapper);
        $subpluginwrapper->add_child($subpluginelement);

        // Set source to populate the data.
        $subpluginelement->set_source_table('assignsubmission_qpy',
                                            ['submission' => backup::VAR_PARENTID]);

        $this->add_question_usages($subpluginelement, 'questionusageid');

        return $subplugin;
    }

    protected function annotate_question_categories() {
        global $DB;

        $backupid = $this->task->get_backupid();
        $categories = $DB->get_records_sql(
            "SELECT qc.id, qc.parent
                   FROM {question_references} qr
                   JOIN {question_bank_entries} qbe ON (qbe.id = qr.questionbankentryid)
                   JOIN {question_categories} qc ON (qc.id = qbe.questioncategoryid)
                  WHERE usingcontextid = :context AND component = 'assignsubmission_qpy'
                            AND questionarea = 'main'", [
                'context' => $this->task->get_contextid(),
            ]
        );

        foreach ($categories as $category) {
            backup_structure_dbops::insert_backup_ids_record($backupid, 'question_category', $category->id);

            // All ancestors need to be annotated. That way, the categories and questions can be restored accordingly.
            $this->annotate_question_category_ancestors($category->parent, $backupid);

            // Only annotated question_bank_entry need to be included in backup, not all questions.
            backup_structure_dbops::insert_backup_ids_record($backupid, 'question_category_partial', $category->id);
        }
    }

    protected function annotate_question_category_ancestors($parentid, $backupid) {
        global $DB;

        if ($parentid) {
            backup_structure_dbops::insert_backup_ids_record($backupid, 'question_category', $parentid);
            $parentparentid = $DB->get_field('question_categories', 'parent', ['id' => $parentid]);
            $this->annotate_question_category_ancestors($parentparentid, $backupid);
        }
    }
}
