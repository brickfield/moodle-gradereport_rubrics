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
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/lib.php');
use gradereport_rubrics\report;
require_once("select_form.php");

$activityid      = optional_param('activityid', 0, PARAM_INT);
$displaylevel    = optional_param('displaylevel', 1, PARAM_INT);
$displayremark   = optional_param('displayremark', 1, PARAM_INT);
$displaysummary  = optional_param('displaysummary', 1, PARAM_INT);
$displayidnumber = optional_param('displayidnumber', 0, PARAM_INT);
$displayemail    = optional_param('displayemail', 1, PARAM_INT);
$courseid        = required_param('id', PARAM_INT); // Course id.
$download        = optional_param('download', '', PARAM_ALPHA); // Set by flexible_table download button.

if (!$course = get_course($courseid)) {
    throw new moodle_exception(get_string('invalidcourseid', 'gradereport_rubrics'));
}

$PAGE->set_url(new moodle_url('/grade/report/rubrics/index.php', [
    'id'              => $courseid,
    'activityid'      => $activityid,
    'displaylevel'    => $displaylevel,
    'displayremark'   => $displayremark,
    'displaysummary'  => $displaysummary,
    'displayidnumber' => $displayidnumber,
    'displayemail'    => $displayemail,
]));

require_login($courseid);

$context = context_course::instance($course->id);

require_capability('gradereport/rubrics:view', $context);

$activityname    = '';
$displayfeedback = false;

// Set up the form.
$mform = new report_rubrics_select_form(null, ['courseid' => $courseid, 'activityid' => $activityid]);

// Only process the activity-select form when this is not a flexible_table download request.
// The download button submits a POST with a 'download' param; if mform->get_data() consumes
// that POST and redirect() fires, the download request is discarded before the table can
// handle it. Skipping form processing on download requests lets flexible_table's setup()
// see the POST intact and send the correct file response headers.
if (empty($download) && ($formdata = $mform->get_data())) {
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
    $displayfeedback = report::GRADABLES[$cm->modname]['showfeedback'] ?? false;
}

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
    $displayfeedback,
    null
);

// Initialise the flexible_table early so it can send download headers before any page HTML
// is output. setup() is called inside init_table(); is_downloading() is usable immediately
// after this call. On a download request, anything printed before this point would corrupt
// the file — the empty($download) guard above ensures nothing is output before here.
$table = $report->init_table($download);

if (!$table->is_downloading()) {
    $PAGE->set_pagelayout('report');
    $actionurl = new moodle_url('/grade/report/rubrics/index.php', ['id' => $courseid]);
    $actionbar = new \core_grades\output\general_action_bar($context, $actionurl, 'report', 'rubrics');
    $label = get_string('pluginname', 'gradereport_rubrics') .
        $OUTPUT->help_icon('pluginname', 'gradereport_rubrics');
    print_grade_page_head($courseid, 'report', 'rubrics', $label, false, false, true, null, null, null, $actionbar);
    $mform->display();
    grade_regrade_final_grades($courseid);
}

$report->show($table);

if (!$table->is_downloading()) {
    echo $OUTPUT->footer();
}
