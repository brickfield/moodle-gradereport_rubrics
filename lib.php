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
 *
 * @package    grade_report_rubrics
 * @copyright  2014 Learning Technology Services, www.lts.ie - Lead Developer: Karen Holland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/grade/report/lib.php');

class grade_report_rubrics extends grade_report {

    public $output;

    public function __construct($courseid, $gpr, $context, $page=null) {
        parent::__construct($courseid, $gpr, $context, $page);
        $this->course_grade_item = grade_item::fetch_course_item($this->courseid);
    }

    public function process_data($data) {
    }

    public function process_action($target, $action) {
    }

    public function show() {
        global $DB, $CFG;

        $output = "";
        $assignmentid = $this->assignmentid;
        if ($assignmentid == 0) {
            return($output);
        } // Disabling all assignments option.

        // Step one, find all enrolled users to course.

        $coursecontext = context_course::instance($this->course->id);
        $users = get_enrolled_users($coursecontext, $withcapability = 'mod/assign:submit', $groupid = 0,
            $userfields = 'u.*', $orderby = 'u.lastname');
        $data = array();

        // Process relevant grading area id from assignmentid and courseid.

        $area = $DB->get_record_sql('select gra.id as areaid from {course_modules} cm'.
            ' join {context} con on cm.id=con.instanceid'.
            ' join {grading_areas} gra on gra.contextid = con.id'.
            ' where cm.module = ? and cm.course = ? and cm.instance = ? and gra.activemethod = ?',
            array(1, $this->course->id, $assignmentid, 'rubric'));

        $rubricarray = array();

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
            // Links for download.

            $linkurl = "index.php?id={$this->course->id}&amp;assignmentid={$this->assignmentid}&amp;".
                "displaylevel={$this->displaylevel}&amp;displayremark={$this->displayremark}&amp;".
                "displaysummary={$this->displaysummary}&amp;displayemail={$this->displayemail}&amp;".
                "displayidnumber={$this->displayidnumber}&amp;format=";

            if ((!$this->csv)) {
                $output = get_string('html_warning', 'gradereport_rubrics').'<br/>'.'<ul class="rubrics-actions"><li><a href="'.$linkurl.'csv">'.
                    get_string('csvdownload', 'gradereport_rubrics').'</a></li>
                    <li><a href="'.$linkurl.'excelcsv">'.
                    get_string('excelcsvdownload', 'gradereport_rubrics').'</a></li></ul>';
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

    public function display_table($data, $rubricarray) {
        global $DB, $CFG;

        $summaryarray = array();
        $csvarray = array();

        $output = html_writer::start_tag('div', array('class' => 'rubrics'));
        $table = new html_table();
        $table->head = array(get_string('student', 'gradereport_rubrics'));
        if ($this->displayidnumber) {
            $table->head[] = get_string('studentid', 'gradereport_rubrics');
        }
        if ($this->displayemail) {
            $table->head[] = get_string('studentemail', 'gradereport_rubrics');
        }
        foreach ($rubricarray as $key => $value) {
            $table->head[] = $rubricarray[$key]['crit_desc'];
        }
        if ($this->displayremark) { $table->head[] = get_string('feedback', 'gradereport_rubrics'); }
        $table->head[] = get_string('grade', 'gradereport_rubrics');
        $csvarray[] = $table->head;
        $table->data = array();
        $table->data[] = new html_table_row();

        foreach ($data as $key => $values) {
            $csvrow = array();
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
                $cell = new html_table_cell();
                $cell->text = "<div class=\"rubrics_points\">".round($rubricarray[$value->criterionid][$value->levelid]->score, 2)." points</div>";
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

            if ($this->displayremark) {
                $cell = new html_table_cell();
                if (is_object($values[3])) { $cell->text = strip_tags($values[3]->feedback); } // Feedback cell.
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
            $csvsummaryrow = array(get_string('summary', 'gradereport_rubrics'));
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

    public function csv_quote($value, $excel) {
        if ($excel) {
            return core_text::convert('"'.str_replace('"', "'", $value).'"', 'UTF-8', 'UTF-16LE');
        } else {
            return '"'.str_replace('"', "'", $value).'"';
        }
    }

    private function get_moodle_grades() {
        global $DB, $CFG;

        $grades = $DB->get_records('grade_grades', array('itemid' => $this->course_grade_item->id), 'userid', 'userid, finalgrade');
        if (!is_array($grades)) {
            $grades = array();
        }

        $this->moodle_grades = array();

        if ($this->course_grade_item->gradetype == GRADE_TYPE_SCALE) {
            $config = get_config('grade_report_rubrics');
            $pgscale = new grade_scale(array('id' => $config->scale));
            $scaleitems = $pgscale->load_items();
            foreach ($this->moodle_students as $st) {
                if (isset($grades[$st->id])) {
                    $fg = (int)$grades[$st->id]->finalgrade;
                    if (isset($scaleitems[$fg - 1])) {
                        $this->moodle_grades[$st->id] = $scaleitems[$fg - 1];
                    } else {
                        $this->moodle_grades[$st->id] = null;
                    }
                } else {
                    $this->moodle_grades[$st->id] = null;
                }
            }
        } else {
            foreach ($this->moodle_students as $st) {
                if (isset($grades[$st->id])) {
                    $this->moodle_grades[$st->id] = grade_format_gradevalue($grades[$st->id]->finalgrade,
                                                                        $this->course_grade_item, true,
                                                                        $this->course_grade_item->get_displaytype(), null);
                } else {
                    $this->moodle_grades[$st->id] = null;
                }
            }
        }
    }
}
