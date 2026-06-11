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
 * Unit tests for gradereport_rubrics report class.
 *
 * @package    gradereport_rubrics
 * @copyright  2026 Brickfield Education Labs <https://www.brickfield.ie/>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace gradereport_rubrics;

/**
 * Tests for the gradereport_rubrics report class.
 *
 * @package    gradereport_rubrics
 * @copyright  2026 Brickfield Education Labs <https://www.brickfield.ie/>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \gradereport_rubrics\report
 */
class report_test extends \advanced_testcase {

    /**
     * Test that GRADABLES defines the expected activity types with required keys.
     */
    public function test_gradables_constant_structure(): void {
        $gradables = report::GRADABLES;

        $this->assertArrayHasKey('assign', $gradables, 'assign must be a supported gradable type');
        $this->assertArrayHasKey('forum', $gradables, 'forum must be a supported gradable type');

        foreach ($gradables as $modname => $config) {
            $this->assertArrayHasKey('table', $config, "$modname must define a table");
            $this->assertArrayHasKey('field', $config, "$modname must define a field");
            $this->assertArrayHasKey('itemoffset', $config, "$modname must define an itemoffset");
            $this->assertArrayHasKey('showfeedback', $config, "$modname must define showfeedback");
        }
    }

    /**
     * Test that enrolled students are discoverable via get_enrolled_users for a course.
     *
     * This mirrors the enrolment lookup in report::show(), which calls
     * get_enrolled_users($coursecontext, 'mod/assign:submit').
     */
    public function test_get_enrolled_users(): void {
        $this->resetAfterTest();

        $course   = $this->getDataGenerator()->create_course();
        $student1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $student2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $context  = \context_course::instance($course->id);
        $enrolled = get_enrolled_users($context, 'mod/assign:submit');

        $this->assertNotEmpty($enrolled, 'Enrolled students must be returned');
        $enrolledids = array_keys($enrolled);
        $this->assertContains($student1->id, $enrolledids, 'Student 1 must appear in enrolled list');
        $this->assertContains($student2->id, $enrolledids, 'Student 2 must appear in enrolled list');
    }

    /**
     * Test that a teacher without the student capability is not returned by the enrolment query.
     */
    public function test_get_enrolled_users_excludes_teachers(): void {
        $this->resetAfterTest();

        $course  = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $context  = \context_course::instance($course->id);
        $enrolled = get_enrolled_users($context, 'mod/assign:submit');

        $enrolledids = array_keys($enrolled);
        $this->assertContains($student->id, $enrolledids, 'Student must be in the enrolled list');
        $this->assertNotContains($teacher->id, $enrolledids, 'Teacher must not appear in the student-capability enrolled list');
    }

    /**
     * Test that the grading area SQL resolves an area when one exists for the activity.
     *
     * This mirrors the $areasql query in report::show() and report::init_table().
     */
    public function test_grading_area_lookup(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course]);

        $cm      = $DB->get_record('course_modules', ['instance' => $assign->id, 'course' => $course->id]);
        $context = $DB->get_record('context', ['instanceid' => $cm->id, 'contextlevel' => CONTEXT_MODULE]);

        // Insert a grading area as the rubric grading method would.
        $gradearearecord = (object)[
            'contextid'    => $context->id,
            'component'    => 'mod_assign',
            'areaname'     => 'submissions',
            'activemethod' => 'rubric',
        ];
        $DB->insert_record('grading_areas', $gradearearecord);

        // Run the same SQL used in report::show() / report::init_table().
        $areasql = "SELECT gra.id as areaid FROM {course_modules} cm
                 LEFT JOIN {context} con ON cm.id = con.instanceid
                 LEFT JOIN {grading_areas} gra ON gra.contextid = con.id
                     WHERE cm.course = ? AND cm.id = ? AND gra.activemethod = ?";
        $area = $DB->get_record_sql($areasql, [$course->id, $cm->id, 'rubric']);

        $this->assertNotEmpty($area, 'A grading area record must be found for the activity');
        $this->assertNotEmpty($area->areaid, 'areaid must be set on the result');
    }

    /**
     * Test that the grading area lookup returns nothing when no rubric area exists.
     */
    public function test_grading_area_lookup_returns_empty_when_no_area(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course]);
        $cm     = $DB->get_record('course_modules', ['instance' => $assign->id, 'course' => $course->id]);

        $areasql = "SELECT gra.id as areaid FROM {course_modules} cm
                 LEFT JOIN {context} con ON cm.id = con.instanceid
                 LEFT JOIN {grading_areas} gra ON gra.contextid = con.id
                     WHERE cm.course = ? AND cm.id = ? AND gra.activemethod = ?";
        $area = $DB->get_record_sql($areasql, [$course->id, $cm->id, 'rubric']);

        $this->assertFalse($area, 'No grading area must be returned when none has been inserted');
    }

    /**
     * Test that the rubric criteria query returns criteria once a definition and criteria exist.
     *
     * This mirrors the $critsql query in report::init_table() and the $sql in report::show()
     * that builds $rubricarray.
     */
    public function test_rubric_criteria_lookup(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course]);

        $cm      = $DB->get_record('course_modules', ['instance' => $assign->id, 'course' => $course->id]);
        $context = $DB->get_record('context', ['instanceid' => $cm->id, 'contextlevel' => CONTEXT_MODULE]);

        // Set up grading area.
        $areaid = $DB->insert_record('grading_areas', (object)[
            'contextid'    => $context->id,
            'component'    => 'mod_assign',
            'areaname'     => 'submissions',
            'activemethod' => 'rubric',
        ]);

        // Set up grading definition.
        $definitionid = $DB->insert_record('grading_definitions', (object)[
            'areaid'       => $areaid,
            'method'       => 'rubric',
            'name'         => 'Test rubric',
            'timecreated'  => time(),
            'timemodified' => time(),
            'usercreated'  => 2,
            'usermodified' => 2,
        ]);

        // Insert two criteria with levels.
        $crit1id = $DB->insert_record('gradingform_rubric_criteria', (object)[
            'definitionid' => $definitionid,
            'sortorder'    => 1,
            'description'  => 'Criterion One',
            'descriptionformat' => FORMAT_HTML,
        ]);
        $crit2id = $DB->insert_record('gradingform_rubric_criteria', (object)[
            'definitionid' => $definitionid,
            'sortorder'    => 2,
            'description'  => 'Criterion Two',
            'descriptionformat' => FORMAT_HTML,
        ]);

        // Levels for criterion 1.
        $DB->insert_record('gradingform_rubric_levels', (object)[
            'criterionid' => $crit1id, 'score' => 0,  'definition' => 'Poor',      'definitionformat' => FORMAT_HTML,
        ]);
        $DB->insert_record('gradingform_rubric_levels', (object)[
            'criterionid' => $crit1id, 'score' => 50, 'definition' => 'Good',      'definitionformat' => FORMAT_HTML,
        ]);
        $DB->insert_record('gradingform_rubric_levels', (object)[
            'criterionid' => $crit1id, 'score' => 100, 'definition' => 'Excellent', 'definitionformat' => FORMAT_HTML,
        ]);

        // Levels for criterion 2.
        $DB->insert_record('gradingform_rubric_levels', (object)[
            'criterionid' => $crit2id, 'score' => 0,  'definition' => 'Incomplete', 'definitionformat' => FORMAT_HTML,
        ]);
        $DB->insert_record('gradingform_rubric_levels', (object)[
            'criterionid' => $crit2id, 'score' => 30, 'definition' => 'Complete',   'definitionformat' => FORMAT_HTML,
        ]);

        // Run the header-column query from init_table().
        $critsql = "SELECT crit.id, crit.description, MAX(lev.score) AS max_score
                      FROM {grading_definitions} def
                 LEFT JOIN {gradingform_rubric_criteria} crit ON crit.definitionid = def.id
                 LEFT JOIN {gradingform_rubric_levels} lev ON lev.criterionid = crit.id
                     WHERE def.areaid = ?
                  GROUP BY crit.id, crit.description, crit.sortorder
                  ORDER BY crit.sortorder";
        $criteria = $DB->get_records_sql($critsql, [$areaid]);

        $this->assertCount(2, $criteria, 'Two criteria must be returned');

        $critarray = array_values($criteria);
        $this->assertSame('Criterion One', $critarray[0]->description);
        $this->assertSame('100', $critarray[0]->max_score, 'Max score for criterion 1 must be 100');
        $this->assertSame('Criterion Two', $critarray[1]->description);
        $this->assertSame('30', $critarray[1]->max_score, 'Max score for criterion 2 must be 30');
    }

    /**
     * Test the rubricarray structure built in report::show() from the criteria/levels recordset.
     *
     * Inserts real DB records and runs the same SQL and array-building logic used in show(),
     * then asserts the resulting array shape is what display_table() depends on.
     */
    public function test_rubricarray_structure(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course]);

        $cm      = $DB->get_record('course_modules', ['instance' => $assign->id, 'course' => $course->id]);
        $context = $DB->get_record('context', ['instanceid' => $cm->id, 'contextlevel' => CONTEXT_MODULE]);

        $areaid = $DB->insert_record('grading_areas', (object)[
            'contextid'    => $context->id,
            'component'    => 'mod_assign',
            'areaname'     => 'submissions',
            'activemethod' => 'rubric',
        ]);

        $definitionid = $DB->insert_record('grading_definitions', (object)[
            'areaid'       => $areaid,
            'method'       => 'rubric',
            'name'         => 'Test rubric',
            'timecreated'  => time(),
            'timemodified' => time(),
            'usercreated'  => 2,
            'usermodified' => 2,
        ]);

        $critid = $DB->insert_record('gradingform_rubric_criteria', (object)[
            'definitionid'      => $definitionid,
            'sortorder'         => 1,
            'description'       => 'Writing quality',
            'descriptionformat' => FORMAT_HTML,
        ]);
        $levelid = $DB->insert_record('gradingform_rubric_levels', (object)[
            'criterionid'      => $critid,
            'score'            => 75,
            'definition'       => 'Good',
            'definitionformat' => FORMAT_HTML,
        ]);

        // Run the same SQL and array-building logic as report::show().
        $sql = "SELECT crit.id as critid, crit.description, lev.id, lev.score, lev.criterionid,
                       lev.definition, lev.definitionformat
                  FROM {grading_definitions} def
             LEFT JOIN {gradingform_rubric_criteria} crit ON crit.definitionid = def.id
             LEFT JOIN {gradingform_rubric_levels} lev ON lev.criterionid = crit.id
                 WHERE def.areaid = ?
              ORDER BY sortorder";
        $records = $DB->get_recordset_sql($sql, [$areaid]);

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

        $this->assertArrayHasKey($critid, $rubricarray, 'Criterion must appear in rubricarray');
        $this->assertSame('Writing quality', $rubricarray[$critid]['crit_desc']);
        $this->assertSame(75.0, $rubricarray[$critid]['max_score']);
        $this->assertArrayHasKey($levelid, $rubricarray[$critid], 'Level must appear under its criterion');
        $this->assertSame('Good', $rubricarray[$critid][$levelid]->definition);
    }

    /**
     * Test display_table() produces one data row per student with no rubric fillings.
     *
     * When a student has no rubric fillings yet, display_table() should still add a row
     * with nograde placeholders for each criterion column, plus the overall grade column.
     */
    public function test_display_table_no_fillings_adds_placeholder_row(): void {
        $this->resetAfterTest();
        global $CFG;

        require_once($CFG->dirroot . '/grade/report/lib.php');

        $course  = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Build a minimal rubricarray with one criterion and one level.
        $rubricarray = [
            99 => [
                'crit_desc' => 'Test criterion',
                'max_score' => 10.0,
                101        => (object)['id' => 101, 'criterionid' => 99, 'score' => 10, 'definition' => 'Good', 'definitionformat' => FORMAT_HTML],
            ],
        ];

        // $data format: [userid => [fullname, email, fillings, grade_object, idnumber]].
        $gradeobj = (object)['str_grade' => '-', 'feedback' => ''];
        $data = [
            $student->id => [fullname($student), $student->email, [], $gradeobj, $student->idnumber],
        ];

        // Create a minimal report object via a partial mock — we only need display_table()
        // and the display-flag properties; the parent constructor needs a valid course.
        $context = \context_course::instance($course->id);
        $gpr     = new \grade_plugin_return(['type' => 'report', 'plugin' => 'rubrics', 'courseid' => $course->id]);
        $report  = new report($course->id, $gpr, $context, 0, false, false, false, false, false, '', false);

        // Use a real flexible_table in HTML mode (no download) so finish_output() won't stream a file.
        $table = new \flexible_table('test-rubrics-display-table');
        $table->define_baseurl(new \moodle_url('/'));
        $table->define_columns(['student', 'criterion_99', 'grade']);
        $table->define_headers(['Student', 'Test criterion (Max grade: 10)', 'Grade']);
        $table->is_downloading('', 'test', 'Test');
        $table->setup();

        // Capture output — display_table() echoes directly.
        ob_start();
        $report->display_table($table, $data, $rubricarray);
        $output = ob_get_clean();

        // The student's name must appear in the rendered HTML.
        $this->assertStringContainsString(fullname($student), $output,
            'Student name must appear in the table output');
        // A nograde placeholder must appear for the ungraded criterion column.
        $nograde = get_string('nograde', 'gradereport_rubrics');
        $this->assertStringContainsString($nograde, $output,
            'A nograde placeholder must appear when the student has no rubric fillings');
    }
}
