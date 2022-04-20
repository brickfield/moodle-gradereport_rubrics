<?php
// This file is part of the gradereport markingguide plugin
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
namespace gradereport_rubrics;

defined('MOODLE_INTERNAL') || die();

use grade_report;
use grade_item;
use html_writer;
use html_table;
use html_table_cell;
use html_table_row;
use moodle_url;
use MoodleExcelWorkbook;
use csv_export_writer;
use context_course;
require_once($CFG->dirroot.'/grade/report/lib.php');

/**
 * Provides rubric report render functionality.
 *
 * @package    gradereport_rubrics
 * @copyright  2021 onward Brickfield Education Labs Ltd, https://www.brickfield.ie
 * @author     2021 Clayton Darlington <clayton@brickfieldlabs.ie>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report extends grade_report {

    /**
     * Hold the contructed report for display
     *
     * @var mixed
     */
    public $output;

    /**
     * Initalize a report object
     *
     * @param int $courseid
     * @param object $gpr
     * @param string $context
     * @param int|null $page
     */
    public function __construct($courseid, $gpr, $context, $page=null) {
        parent::__construct($courseid, $gpr, $context, $page);
        $this->course_grade_item = grade_item::fetch_course_item($this->courseid);
    }

    /**
     * Needed definition for grade_report
     *
     * @param array $data
     * @return void
     */
    public function process_data($data) {
    }

    /**
     * Needed definition for grade_report
     *
     * @param string $target
     * @param string $action
     * @return void
     */
    public function process_action($target, $action) {
    }

    /**
     * Generate and display the rubric report
     *
     * @return void
     */
    public function show() {
        global $DB, $CFG;

        $output = "";
        $assignmentid = $this->assignmentid;
        if ($assignmentid == 0) {
            return($output);
        } // Disabling all assignments option.

        // Step one, find all enrolled users to course.
        $coursecontext = context_course::instance($this->courseid);
        $users = get_enrolled_users($coursecontext, $withcapability = 'mod/assign:submit', $groupid = 0,
            $userfields = 'u.*', $orderby = 'u.lastname');
        $data = [];

        // Process relevant grading area id from assignmentid and courseid.
        $gradingarea = $area = $DB->get_record_sql('select gra.id as areaid from {course_modules} cm'.
        ' join {context} con on cm.id=con.instanceid'.
        ' join {grading_areas} gra on gra.contextid = con.id'.
        ' where cm.module = ? and cm.course = ? and cm.instance = ? and gra.activemethod = ?',
        array(1, $this->courseid, $assignmentid, 'rubric'));

         // Step 2, find any rubrics related to assignment.
        $rubricarray = $rubricarray = [];

        // Step 2, find any rubrics related to assignment.
        $definitions = $DB->get_records_sql("select * from {grading_definitions} where areaid = ?", array($area->areaid));
        foreach ($definitions as $def) {
            $criteria = $DB->get_records_sql("select * from {gradingform_rubric_criteria}".
                " where definitionid = ? order by sortorder", array($def->id));
            foreach ($criteria as $crit) {
                $levels = $DB->get_records_sql("select * from {gradingform_rubric_levels} where criterionid = ?", array($crit->id));
                foreach ($levels as $level) {
                    $rubricarray[$crit->id][$level->id] = $level;
                    $rubricarray[$crit->id]['crit_desc'] = $crit->description;
                }
            }
        }

        foreach ($users as $user) {
            $fullname = fullname($user); // Get Moodle fullname.
            $query = "SELECT grf.id, gd.id as defid, ag.userid, ag.grade, grf.instanceid,".
                " grf.criterionid, grf.levelid, grf.remark".
                " FROM {assign_grades} ag".
                " JOIN {grading_instances} gin".
                  " ON ag.id = gin.itemid".
                " JOIN {grading_definitions} gd".
                  " ON (gd.id = gin.definitionid )".
                " JOIN {gradingform_rubric_fillings} grf".
                  " ON (grf.instanceid = gin.id)".
                " WHERE gin.status = ? and ag.assignment = ? and ag.userid = ?";

            $queryarray = array(1, $assignmentid, $user->id);
            $userdata = $DB->get_records_sql($query, $queryarray);

            $query2 = "SELECT gig.feedback".
                " FROM {grade_items} git".
                " JOIN {grade_grades} gig".
                " ON git.id = gig.itemid".
                " WHERE git.iteminstance = ? and git.itemmodule = ? and gig.userid = ?";
            $feedback = $DB->get_record_sql($query2, array($assignmentid, 'assign',  $user->id));
            $data[$user->id] = array($fullname, $user->email, $userdata, $feedback, $user->idnumber);
        }

        if (count($data) == 0) {
            $output = get_string('err_norecords', 'gradereport_rubrics');
        } else {

            $csvlink = new moodle_url('/grade/report/rubrics/index.php', [
                'id' => $this->course->id,
                'assignmentid' => $this->assignmentid,
                'displaylevl' => $this->displaylevel,
                'displayremark' => $this->displayremark,
                'displaysummary' => $this->displaysummary,
                'displayemail' => $this->displayemail,
                'displayidnumber' => $this->displayidnumber,
                'format' => 'csv',
            ]);

            $xlsxlink = new moodle_url('/grade/report/rubrics/index.php', [
                'id' => $this->course->id,
                'assignmentid' => $this->assignmentid,
                'displaylevl' => $this->displaylevel,
                'displayremark' => $this->displayremark,
                'displaysummary' => $this->displaysummary,
                'displayemail' => $this->displayemail,
                'displayidnumber' => $this->displayidnumber,
                'format' => 'excelcsv',
            ]);
            // Links for download.
            if ((!$this->csv)) {
                $output .= html_writer::start_tag('ul', ['class' => 'rubrics-actions']);
                $output .= html_writer::start_tag('li');
                $output .= html_writer::link($csvlink, get_string('csvdownload', 'gradereport_rubrics'));
                $output .= html_writer::end_tag('il');
                $output .= html_writer::start_tag('li');
                $output .= html_writer::link($xlsxlink, get_string('excelcsvdownload', 'gradereport_rubrics'));
                $output .= html_writer::end_tag('il');
                $output .= html_writer::end_tag('ul');

                // Put data into table.
                $output .= $this->display_table($data, $rubricarray);
            } else {
                // Put data into array, not string, for csv download.
                $output = $this->display_table($data, $rubricarray);
            }
        }

        $this->output = $output;
        if (!$this->csv) {
            echo $output;
        } else {
            if ($this->excel) {
                require_once("$CFG->libdir/excellib.class.php");

                $filename = "rubricreport_{$this->assignmentname}.xls";
                $downloadfilename = clean_filename($filename);
                // Creating a workbook.
                $workbook = new MoodleExcelWorkbook("-");
                // Sending HTTP headers.
                $workbook->send($downloadfilename);
                // Adding the worksheet.
                $myxls = $workbook->add_worksheet($filename);

                $row = 0;
                // Running through data.
                foreach ($output as $value) {
                    $col = 0;
                    foreach ($value as $newvalue) {
                        $myxls->write_string($row, $col, $newvalue);
                        $col++;
                    }
                    $row++;
                }

                $workbook->close();
                exit;
            } else {
                require_once($CFG->libdir .'/csvlib.class.php');

                $filename = "rubricreport_{$this->assignmentname}";
                $csvexport = new csv_export_writer();
                $csvexport->set_filename($filename);

                foreach ($output as $value) {
                    $csvexport->add_data($value);
                }
                $csvexport->download_file();

                exit;
            }
        }
    }

    /**
     * Display the table
     *
     * @param array $data
     * @param array $rubricarray
     * @return void
     */
    public function display_table($data, $rubricarray) {
        global $DB, $CFG;
        $summaryarray = [];
        $csvarray = [];

        $output = html_writer::start_tag('div', ['class' => 'rubrics']);
        $table = new html_table();
        $table->head = [get_string('student', 'gradereport_rubrics')];
        if ($this->displayidnumber) {
            $table->head[] = get_string('studentid', 'gradereport_rubrics');
        }
        if ($this->displayemail) {
            $table->head[] = get_string('studentemail', 'gradereport_rubrics');
        }
        foreach ($rubricarray as $key => $value) {
            $table->head[] = $rubricarray[$key]['crit_desc'];
        }
        if ($this->displayremark) {
            $table->head[] = get_string('feedback', 'gradereport_rubrics');
        }
        $table->head[] = get_string('grade', 'gradereport_rubrics');
        $csvarray[] = $table->head;
        $table->data = [];
        $table->data[] = new html_table_row();

        foreach ($data as $key => $values) {
            $csvrow = [];
            $row = new html_table_row();
            $cell = new html_table_cell();
            $cell->text = $values[0]; // Student name.
            $csvrow[] = $values[0];
            $row->cells[] = $cell;
            if ($this->displayidnumber) {
                $cell = new html_table_cell();
                $cell->text = $values[4]; // Student id.
                $row->cells[] = $cell;
                $csvrow[] = $values[4];
            }
            if ($this->displayemail) {
                $cell = new html_table_cell();
                $cell->text = $values[1]; // Student email.
                $row->cells[] = $cell;
                $csvrow[] = $values[1];
            }
            $thisgrade = get_string('nograde', 'gradereport_rubrics');
            if (count($values[2]) == 0) { // Students with no marks, add fillers.
                foreach ($rubricarray as $key => $value) {
                    $cell = new html_table_cell();
                    $cell->text = get_string('nograde', 'gradereport_rubrics');
                    $row->cells[] = $cell;
                    $csvrow[] = $thisgrade;
                }
            }
            foreach ($values[2] as $value) {
                if (is_object($value)) {
                    $cell = new html_table_cell();
                    $cell->text = "<div class=\"rubrics_points\">".
                        round($rubricarray[$value->criterionid][$value->levelid]->score, 2).
                        " points</div>";
                    $csvtext = round($rubricarray[$value->criterionid][$value->levelid]->score, 2)." points - ";
                    if ($this->displaylevel) {
                        $cell->text .= "<div class=\"rubrics_level\">".$rubricarray[$value->criterionid][$value->levelid]->definition."</div>";
                        $csvtext .= $rubricarray[$value->criterionid][$value->levelid]->definition." - ";
                    }
                    if ($this->displayremark) {
                        $cell->text .= $value->remark;
                        $csvtext .= $value->remark;
                    }
                    $row->cells[] = $cell;
                    $thisgrade = round($value->grade, 2); // Grade cell.

                    if (!array_key_exists($value->criterionid, $summaryarray)) {
                        $summaryarray[$value->criterionid]["sum"] = 0;
                        $summaryarray[$value->criterionid]["count"] = 0;
                    }
                    $summaryarray[$value->criterionid]["sum"] += $rubricarray[$value->criterionid][$value->levelid]->score;
                    $summaryarray[$value->criterionid]["count"]++;

                    $csvrow[] = $csvtext;
                }
            }

            if ($this->displayremark) {
                $cell = new html_table_cell();
                if (is_object($values[3])) { $cell->text = $values[3]->feedback; } // Feedback cell.
                if (empty($cell->text)) {
                    $cell->text = get_string('nograde', 'gradereport_rubrics');
                }
                $row->cells[] = $cell;
                $csvrow[] = $cell->text;
                $summaryarray["feedback"]["sum"] = get_string('feedback', 'gradereport_rubrics');
            }

            $cell = new html_table_cell();
            $cell->text = $thisgrade; // Grade cell.
            $csvrow[] = $cell->text;
            if ($thisgrade != get_string('nograde', 'gradereport_rubrics')) {
                if (!array_key_exists("grade", $summaryarray)) {
                    $summaryarray["grade"]["sum"] = 0;
                    $summaryarray["grade"]["count"] = 0;
                }
                $summaryarray["grade"]["sum"] += $thisgrade;
                $summaryarray["grade"]["count"]++;
            }
            $row->cells[] = $cell;
            $table->data[] = $row;
            $csvarray[] = $csvrow;
        }

        // Summary row.
        if ($this->displaysummary) {
            $row = new html_table_row();
            $cell = new html_table_cell();
            $cell->text = get_string('summary', 'gradereport_rubrics');
            $row->cells[] = $cell;
            $csvsummaryrow = [get_string('summary', 'gradereport_rubrics')];
            if ($this->displayidnumber) { // Adding placeholder cells.
                $cell = new html_table_cell();
                $cell->text = " ";
                $row->cells[] = $cell;
                $csvsummaryrow[] = $cell->text;
            }
            if ($this->displayemail) { // Adding placeholder cells.
                $cell = new html_table_cell();
                $cell->text = " ";
                $row->cells[] = $cell;
                $csvsummaryrow[] = $cell->text;
            }
            foreach ($summaryarray as $sum) {
                $cell = new html_table_cell();
                if ($sum["sum"] == get_string('feedback', 'gradereport_rubrics')) {
                    $cell->text = " ";
                } else {
                    $cell->text = round($sum["sum"] / $sum["count"], 2);
                }
                $row->cells[] = $cell;
                $csvsummaryrow[] = $cell->text;
            }
            $table->data[] = $row;
            $csvarray[] = $csvsummaryrow;
        }

        if ($this->csv) {
            $output = $csvarray;
        } else {
            $output .= html_writer::table($table);
            $output .= html_writer::end_tag('div');
        }

        return $output;
    }
}
