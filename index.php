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
 * Gradebook rubrics report
 * @package    gradereport_rubrics
 * @copyright  2014 Learning Technology Services, www.lts.ie - Lead Developer: Karen Holland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../../config.php');
require_once($CFG->libdir .'/gradelib.php');
require_once($CFG->dirroot.'/grade/lib.php');
use gradereport_rubrics\report;
require_once("select_form.php");

$activityid    = optional_param('activityid', 0, PARAM_INT);
$displaylevel  = optional_param('displaylevel', 1, PARAM_INT);
$displayremark = optional_param('displayremark', 1, PARAM_INT);
$displaysummary  = optional_param('displaysummary', 1, PARAM_INT);
$displayidnumber = optional_param('displayidnumber', 1, PARAM_INT);
$displayemail  = optional_param('displayemail', 1, PARAM_INT);
$courseid      = required_param('id', PARAM_INT); // Course id.

if (!$course = get_course($courseid)) {
    throw new moodle_exception(get_string('invalidcourseid', 'gradereport_rubrics'));
}

$PAGE->set_url(new moodle_url('/grade/report/rubrics/index.php', ['id' => $courseid]));

require_login($courseid);
$PAGE->set_pagelayout('report');

$context = context_course::instance($course->id);

require_capability('gradereport/rubrics:view', $context);

$activityname = '';

// Set up the form.
$mform = new report_rubrics_select_form(null, ['courseid' => $courseid, 'activityid' => $activityid]);

// Did we get anything from the form?
if ($formdata = $mform->get_data()) {
    $activityid = $formdata->activityid;
    $config = get_config('gradereport_rubrics');
    if (!empty($config->displayurlparams)) {
        $fullurl = new moodle_url('/grade/report/rubrics/index.php', (array)$formdata);
        redirect($fullurl);
    }
}

if ($activityid != 0) {
    $cm = get_fast_modinfo($courseid)->cms[$activityid];
    $activityname = format_string($cm->name, true, ['context' => $context]);
    // Determine whether or not to display general feedback.
    $displayfeedback = report::GRADABLES[$cm->modname]['showfeedback'] ?? false;
}

print_grade_page_head($COURSE->id, 'report', 'rubrics',
    get_string('pluginname', 'gradereport_rubrics') .
    $OUTPUT->help_icon('pluginname', 'gradereport_rubrics'));

$mform->display();

grade_regrade_final_grades($courseid); // First make sure we have proper final grades.

$gpr = new grade_plugin_return(['type' => 'report', 'plugin' => 'grader',
    'courseid' => $courseid]); // Return tracking object.
$report = new report(
    $courseid,
    $gpr,
    $context,
    $activityid,
    ($displaylevel == 1),
    ($displayremark == 1),
    ($displaysummary == 1),
    ($displayidnumber == 1),
    ($displayemail == 1),
    $activityname,
    $displayfeedback ?? false,
    null
);

$report->show();

echo $OUTPUT->footer();
