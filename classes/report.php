<?php
// This file is part of the gradereport rubrics plugin
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
use flexible_table;
use moodle_url;
use context_course;
require_once($CFG->dirroot . '/grade/report/lib.php');

/**
 * Provides rubric report render functionality.
 *
 * @package    gradereport_rubrics
 * @copyright  2021 onward Brickfield Education Labs Ltd, https://www.brickfield.ie
 * @author     2021 Clayton Darlington <clayton@brickfieldlabs.ie>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report extends grade_report {
    /** @var int Activity id. */
    public $activityid = 0;
    /** @var string Activity name. */
    public $activityname = '';
    /** @var bool Whether to display levels. */
    public $displaylevel = false;
    /** @var bool Whether to display remarks. */
    public $displayremark = false;
    /** @var bool Whether to display summary. */
    public $displaysummary = false;
    /** @var bool Whether to display id numbers. */
    public $displayidnumber = false;
    /** @var bool Whether to display emails. */
    public $displayemail = false;
    /** @var bool Whether to display feedback. */
    public $displayfeedback = false;
    /** @var grade_item Course grade items. */
    public $coursegradeitem = null;

    /** @var array Defines variables for each gradable activity. */
    const GRADABLES = [
        'assign' => ['table' => 'assign_grades', 'field' => 'assignment', 'itemoffset' => 0, 'showfeedback' => 1],
        'forum'  => ['table' => 'forum_grades', 'field' => 'forum', 'itemoffset' => 1, 'showfeedback' => 0],
    ];

    /**
     * Initialise a report object
     *
     * @param int $courseid
     * @param object $gpr
     * @param string $context
     * @param int $activityid
     * @param bool $displaylevel
     * @param bool $displayremark
     * @param bool $displaysummary
     * @param bool $displayidnumber
     * @param bool $displayemail
     * @param string $activityname
     * @param bool $displayfeedback
     * @param int|null $page
     */
    public function __construct(
        $courseid,
        $gpr,
        $context,
        $activityid,
        $displaylevel,
        $displayremark,
        $displaysummary,
        $displayidnumber,
        $displayemail,
        $activityname,
        $displayfeedback,
        $page = null
    ) {
        parent::__construct($courseid, $gpr, $context, $page);

        $this->activityid      = $activityid;
        $this->displaylevel    = $displaylevel;
        $this->displayremark   = $displayremark;
        $this->displaysummary  = $displaysummary;
        $this->displayidnumber = $displayidnumber;
        $this->displayemail    = $displayemail;
        $this->activityname    = $activityname;
        $this->displayfeedback = $displayfeedback;

        $this->coursegradeitem = grade_item::fetch_course_item($this->courseid);

        // Handling course group mode.
        $this->pbarurl = $this->get_base_url();
        $this->setup_groups();
    }

    /**
     * Build the report URL carrying the current activity and display options.
     *
     * Used as the flexible_table base URL and as the group selector's submit target,
     * so both preserve the options the user already chose.
     *
     * @return moodle_url
     */
    protected function get_base_url(): moodle_url {
        return new moodle_url('/grade/report/rubrics/index.php', [
            'id'              => $this->courseid,
            'activityid'      => $this->activityid,
            'displaylevel'    => (int)$this->displaylevel,
            'displayremark'   => (int)$this->displayremark,
            'displaysummary'  => (int)$this->displaysummary,
            'displayemail'    => (int)$this->displayemail,
            'displayidnumber' => (int)$this->displayidnumber,
        ]);
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
     * Initialise, configure, and set up the flexible_table instance.
     *
     * This method resolves the full column list — including dynamic rubric criterion
     * columns — and calls setup() before returning. That means is_downloading() is
     * usable immediately after this call, which allows index.php to suppress page HTML
     * on download requests before any output is sent. flexible_table::setup() sends the
     * file response headers on download requests; those headers must arrive before any
     * HTML is printed, so setup() must be called here, not deferred to display_table().
     *
     * @param string $download
     * @return flexible_table
     */
    public function init_table(string $download = ''): flexible_table {
        global $DB;

        $columns = ['student'];
        $headers = [get_string('student', 'gradereport_rubrics')];

        if ($this->displayidnumber) {
            $columns[] = 'idnumber';
            $headers[] = get_string('studentid', 'gradereport_rubrics');
        }
        if ($this->displayemail) {
            $columns[] = 'email';
            $headers[] = get_string('studentemail', 'gradereport_rubrics');
        }

        // Resolve rubric criterion columns now so setup() can be called before any output.
        // This is a lightweight query — only criterion descriptions are needed for headers.
        if ($this->activityid != 0) {
            $areasql = "SELECT gra.id as areaid FROM {course_modules} cm
                     LEFT JOIN {context} con ON cm.id = con.instanceid AND con.contextlevel = ?
                     LEFT JOIN {grading_areas} gra ON gra.contextid = con.id
                         WHERE cm.course = ? AND cm.id = ? AND gra.activemethod = ?";
            $area = $DB->get_record_sql($areasql, [CONTEXT_MODULE, $this->courseid, $this->activityid, 'rubric']);

            if ($area) {
                $critsql = "SELECT crit.id, crit.description, MAX(lev.score) AS max_score
                              FROM {grading_definitions} def
                         LEFT JOIN {gradingform_rubric_criteria} crit ON crit.definitionid = def.id
                         LEFT JOIN {gradingform_rubric_levels} lev ON lev.criterionid = crit.id
                             WHERE def.areaid = ?
                          GROUP BY crit.id, crit.description, crit.sortorder
                          ORDER BY crit.sortorder";
                $criteria = $DB->get_records_sql($critsql, [$area->areaid]);

                $ishtml = ($download === '');

                foreach ($criteria as $crit) {
                    $columns[] = 'criterion_' . $crit->id;
                    $headers[] = get_string('criterion_label', 'gradereport_rubrics', (object)[
                        'crit_desc' => $ishtml ? s($crit->description) : $crit->description,
                        'max_score' => round($crit->max_score, 2),
                    ]);
                }
            }
        }

        if ($this->displayremark && $this->displayfeedback) {
            $columns[] = 'feedback';
            $headers[] = get_string('feedback', 'gradereport_rubrics');
        }
        $columns[] = 'grade';
        $headers[] = get_string('grade', 'gradereport_rubrics');

        $table = new flexible_table('gradereport-rubrics-' . $this->activityid);
        $table->define_baseurl($this->get_base_url());
        $table->set_attribute('class', 'rubrics generaltable');
        $table->set_attribute('summary', get_string('pluginname', 'gradereport_rubrics') . ': ' . $this->activityname);
        $table->sortable(false);
        $table->collapsible(false);
        $table->show_download_buttons_at([TABLE_P_BOTTOM]);

        // Check status of page, is_downloading() or not.
        $tmpcourse = get_fast_modinfo($this->courseid)->get_course();
        $filename = clean_filename(($this->activityname ?: 'rubrics') . '_' . $tmpcourse->shortname);
        $table->is_downloading($download, $filename, get_string('pluginname', 'gradereport_rubrics'));

        // Define columns and headers before calling setup().
        $table->define_columns($columns);
        $table->define_headers($headers);
        $table->define_header_column('student');
        $table->setup();

        return $table;
    }

    /**
     * Generate and display the rubric report
     *
     * @param flexible_table $table The table instance returned by init_table(), after
     *                              page output decisions have been made in index.php.
     * @return void
     */
    public function show(flexible_table $table) {
        global $DB;

        $activityid = $this->activityid;
        if ($activityid == 0) {
            return;
        }

        // Validating activityid exists.
        $modinfo = get_fast_modinfo($this->courseid);
        if (!isset($modinfo->cms[$activityid]) || !isset(self::GRADABLES[$modinfo->cms[$activityid]->modname])) {
            if (!$table->is_downloading()) {
                echo get_string('err_norecords', 'gradereport_rubrics');
            }
            return;
        }

        // Restricting by group mode if needed.
        if ($this->currentgroup == -2) {
            if (!$table->is_downloading()) {
                echo get_string('err_norecords', 'gradereport_rubrics');
            }
            return;
        }
        $groupid = ($this->currentgroup > 0) ? $this->currentgroup : 0;

        // Find all enrolled users in the course, within the permitted group.
        $coursecontext = context_course::instance($this->courseid);
        $users = get_enrolled_users($coursecontext, 'mod/assign:submit', $groupid, 'u.*', 'u.lastname');
        if (!$users) {
            if (!$table->is_downloading()) {
                echo get_string('err_norecords', 'gradereport_rubrics');
            }
            return;
        }

        // Find the grading area for this activity.
        $areasql = "SELECT gra.id as areaid FROM {course_modules} cm
                 LEFT JOIN {context} con ON cm.id = con.instanceid AND con.contextlevel = ?
                 LEFT JOIN {grading_areas} gra ON gra.contextid = con.id
                     WHERE cm.course = ? AND cm.id = ? AND gra.activemethod = ?";
        $area = $DB->get_record_sql($areasql, [CONTEXT_MODULE, $this->courseid, $activityid, 'rubric']);

        // An assign/forum can be gradable without having a rubric grading area defined.
        if (!$area) {
            if (!$table->is_downloading()) {
                echo get_string('err_norecords', 'gradereport_rubrics');
            }
            return;
        }

        // Find rubric criteria and levels for this activity.
        $sql = "SELECT crit.id as critid, crit.description, lev.id, lev.score, lev.criterionid, lev.definition, lev.definitionformat
                  FROM {grading_definitions} def
             LEFT JOIN {gradingform_rubric_criteria} crit ON crit.definitionid = def.id
             LEFT JOIN {gradingform_rubric_levels} lev ON lev.criterionid = crit.id
                 WHERE def.areaid = ?
              ORDER BY sortorder";
        $records = $DB->get_recordset_sql($sql, [$area->areaid]);

        $rubricarray = [];
        foreach ($records as $record) {
            $rubricarray[$record->critid][$record->id] = (object)[
                'id'               => $record->id,
                'criterionid'      => $record->criterionid,
                'score'            => $record->score,
                'definition'       => $record->definition,
                'definitionformat' => $record->definitionformat,
            ];
            $rubricarray[$record->critid]['crit_desc'] = $record->description;

            if (
                !isset($rubricarray[$record->critid]['max_score'])
                || ($rubricarray[$record->critid]['max_score'] < $record->score)
            ) {
                $rubricarray[$record->critid]['max_score'] = round($record->score, 2);
            }
        }
        $records->close();

        // Map activity type to its DB table and field via GRADABLES.
        $activity = $modinfo->cms[$activityid];
        $gradable = self::GRADABLES[$activity->modname];

        $userids = [];
        foreach ($users as $user) {
            $userids[] = $user->id;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids);
        $inparams[] = 1;
        $inparams[] = $activity->instance;
        $inparams[] = $activity->context->id;

        $dbtable  = $gradable['table'];
        $field     = $gradable['field'];
        $orderextra = ($dbtable == 'assign_grades') ? ", act.attemptnumber DESC" : "";

        $sql = "SELECT act.id, act.userid, fill.id, def.id as defid, act.grade,
                       fill.instanceid, fill.criterionid, fill.levelid, fill.remark
                  FROM {" . $dbtable . "} act
             LEFT JOIN {grading_instances} inst ON act.id = inst.itemid
             LEFT JOIN {grading_definitions} def ON inst.definitionid = def.id
             LEFT JOIN {grading_areas} area ON def.areaid = area.id
             LEFT JOIN {gradingform_rubric_fillings} fill ON inst.id = fill.instanceid
             LEFT JOIN {gradingform_rubric_criteria} crit ON crit.id = fill.criterionid
                 WHERE act.userid $insql AND inst.status = ? AND act.{$field} = ? AND area.contextid = ?
              ORDER BY act.userid ASC" . $orderextra . ", crit.sortorder ASC";

        $userdata = $DB->get_recordset_sql($sql, $inparams);
        $udataarray = [];
        foreach ($userdata as $udata) {
            if (!isset($udataarray[$udata->userid])) {
                $udataarray[$udata->userid] = [];
            }
            $udataarray[$udata->userid][] = $udata;
        }
        $userdata->close();

        $fullgrade = \grade_get_grades($this->courseid, 'mod', $activity->modname, $activity->instance, $userids);

        $data = [];
        foreach ($users as $user) {
            $fullname  = fullname($user);
            $userd     = isset($udataarray[$user->id]) ? $udataarray[$user->id] : [];
            $offset    = $gradable['itemoffset'];
            // Checking itemoffset exists.
            $feedback  = $fullgrade->items[$offset]->grades[$user->id] ?? null;
            $data[$user->id] = [$fullname, $user->email, $userd, $feedback, $user->idnumber];
        }

        if (count($data) == 0) {
            if (!$table->is_downloading()) {
                echo get_string('err_norecords', 'gradereport_rubrics');
            }
            return;
        }

        $this->display_table($table, $data, $rubricarray);
    }

    /**
     * Populate and render the rubric data table.
     * flexible_table handles both HTML display and file downloads via its built-in
     * download mechanism, including correct encoding for non-ASCII content.
     *
     * @param flexible_table $table  Configured table instance from init_table()
     * @param array $data            Keyed by userid: [fullname, email, rubric fillings, grade object, idnumber]
     * @param array $rubricarray     Rubric criteria and levels, keyed by criterion id
     * @return void Outputs directly in all modes
     */
    public function display_table(flexible_table $table, array $data, array $rubricarray) {
        $summaryarray = [];

        // Columns, headers, and setup() were handled in init_table() so that
        // is_downloading() is available before any page HTML is output.
        $downloading = $table->is_downloading();

        foreach ($data as $key => $values) {
            $row = [];
            $row[] = $downloading ? $values[0] : s($values[0]); // Student name.
            if ($this->displayidnumber) {
                $row[] = $downloading ? $values[4] : s($values[4]);
            }
            if ($this->displayemail) {
                $row[] = $downloading ? $values[1] : s($values[1]);
            }

            $thisgrade = get_string('nograde', 'gradereport_rubrics');

            if (count($values[2]) == 0) {
                foreach ($rubricarray as $rkey => $rvalue) {
                    $row[] = get_string('nograde', 'gradereport_rubrics');
                }
            }

            foreach ($values[2] as $value) {
                if (is_object($value)) {
                    $score = $rubricarray[$value->criterionid][$value->levelid]->score ?? 0;
                    $score = round($score, 2);
                    $critgrade = get_string('criterion_grade', 'gradereport_rubrics', $score);

                    if ($downloading) {
                        // Plain text for download — no HTML markup.
                        $cellcontent = $critgrade;
                        if ($this->displaylevel) {
                            $level = $rubricarray[$value->criterionid][$value->levelid]->definition ??
                                get_string('notset', 'gradereport_rubrics');
                            $cellcontent .= ' ' . get_string('criterion_level', 'gradereport_rubrics', $level);
                        }
                        if ($this->displayremark) {
                            $cellcontent .= ' ' . strip_tags($value->remark);
                        }
                    } else {
                        // HTML for on-screen display.
                        $cellcontent = html_writer::div($critgrade, 'rubrics_points');
                        if ($this->displaylevel) {
                            $level = $rubricarray[$value->criterionid][$value->levelid]->definition ??
                                get_string('notset', 'gradereport_rubrics');
                            // Level definitions and remarks being displayed correctly here.
                            $critlevel = get_string('criterion_level', 'gradereport_rubrics', s($level));
                            $cellcontent .= html_writer::div($critlevel, 'rubrics_level');
                        }
                        if ($this->displayremark) {
                            $cellcontent .= s($value->remark);
                        }
                    }

                    $row[] = $cellcontent;
                    $thisgrade = round($value->grade, 2);

                    if (!array_key_exists($value->criterionid, $summaryarray)) {
                        $summaryarray[$value->criterionid]['sum']   = 0;
                        $summaryarray[$value->criterionid]['count'] = 0;
                    }
                    $summaryarray[$value->criterionid]['sum']   += $score;
                    $summaryarray[$value->criterionid]['count']++;
                }
            }

            if ($this->displayremark && $this->displayfeedback) {
                $feedbacktext = '';
                if (is_object($values[3]) && (!empty($values[3]->feedback))) {
                    $feedbacktext = strip_tags($values[3]->feedback);
                }
                $row[] = $feedbacktext ?: get_string('nograde', 'gradereport_rubrics');
                $summaryarray['feedback']['sum'] = get_string('feedback', 'gradereport_rubrics');
            }

            if ($thisgrade != get_string('nograde', 'gradereport_rubrics')) {
                if (!array_key_exists('grade', $summaryarray)) {
                    $summaryarray['grade']['sum']   = 0;
                    $summaryarray['grade']['count'] = 0;
                }
                $summaryarray['grade']['sum']   += $thisgrade;
                $summaryarray['grade']['count']++;
            }
            $row[] = is_object($values[3]) ? $values[3]->str_grade : get_string('nograde', 'gradereport_rubrics');
            $table->add_data($row);
        }

        // Summary row.
        if ($this->displaysummary) {
            $summaryrow = [get_string('summary', 'gradereport_rubrics')];
            if ($this->displayidnumber) {
                $summaryrow[] = '';
            }
            if ($this->displayemail) {
                $summaryrow[] = '';
            }
            foreach ($summaryarray as $sum) {
                if ($sum['sum'] == get_string('feedback', 'gradereport_rubrics')) {
                    $summaryrow[] = '';
                } else {
                    $summaryrow[] = round($sum['sum'] / $sum['count'], 2);
                }
            }
            $table->add_data($summaryrow);
        }

        $table->finish_output();
    }
}
