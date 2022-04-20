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
 * Describes the select form for generating a rubics report
 * @package    gradereport_rubrics
 * @copyright  2014 Learning Technology Services, www.lts.ie - Lead Developer: Karen Holland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/formslib.php");

/**
 * Generate the selection form for the rubrics report
 */
class report_rubrics_select_form extends moodleform {

    /**
     * Define the values in the form
     *
     * @return void
     */
    public function definition() {
        global $CFG, $DB;

        $assignments = $DB->get_records_sql('select cm.id, cm.course, con.id as con_id, con.path, '.
            ' gra.id as gra_id, ass.id as assignmentid, ass.name as assignment '.
            ' from {course_modules} cm join {context} con on cm.id=con.instanceid '.
            ' join {grading_areas} gra on gra.contextid = con.id '.
            ' join {assign} ass on ass.id = cm.instance '.
            ' where cm.module = ? and cm.course = ? and gra.activemethod = ?',
            array(1, $this->_customdata['courseid'], 'rubric'));

        $formarray = array(0 => 'Select');

        foreach ($assignments as $item) {
            $formarray[$item->assignmentid] = $item->assignment;
        }

        $mform =& $this->_form;

        // Check for any relevant assignments.
        if (count($assignments) == 0) {
            $mform->addElement ('html', get_string('err_noassignments', 'gradereport_rubrics'));
            return;
        }

        $mform->addElement ('select', 'assignmentid', get_string('selectassignment', 'gradereport_rubrics'), $formarray);
        $mform->setType('assignmentid', PARAM_INT);
        $mform->getElement('assignmentid')->setSelected(0);
        $mform->addElement ('advcheckbox', 'displaylevel', get_string('displaylevel', 'gradereport_rubrics'));
        $mform->getElement('displaylevel')->setValue(1);
        $mform->addElement ('advcheckbox', 'displayremark', get_string('displayremark', 'gradereport_rubrics'));
        $mform->getElement('displayremark')->setValue(1);
        $mform->addElement ('advcheckbox', 'displaysummary', get_string('displaysummary', 'gradereport_rubrics'));
        $mform->getElement('displaysummary')->setValue(1);
        $mform->addElement ('advcheckbox', 'displayemail', get_string('displayemail', 'gradereport_rubrics'));
        $mform->getElement('displayemail')->setValue(0);
        $mform->addElement ('advcheckbox', 'displayidnumber', get_string('displayidnumber', 'gradereport_rubrics'));
        $mform->getElement('displayidnumber')->setValue(0);
        $mform->addElement('hidden', 'id', $this->_customdata['courseid']);
        $mform->setType('id', PARAM_INT);
        $this->add_action_buttons(false, 'Go');
    }
}
