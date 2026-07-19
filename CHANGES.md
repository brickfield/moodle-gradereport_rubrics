# Changelog — gradereport_rubrics

## 1.405.03 (2026071900)

Security fix: rubric text shown in the on-screen report is now HTML-escaped. Sites should
update — a rubric criterion description, level definition or grading remark containing HTML
was previously rendered as markup in the browser of anyone viewing the report.

- Escaped criterion descriptions in column headers, and level definitions and grading
  remarks in report cells, on the HTML display path. These fields are stored as plain text
  and `flexible_table` does not escape header or cell content. The CSV/Excel download path
  was unaffected and is unchanged.
- Escaped the student name, ID number and email cells on the same path, for consistency.
- Validated the `activityid` request parameter against the course's activities and supported
  activity types, and checked the grading-area lookup result before use. A crafted URL
  naming a missing module, an unsupported activity type, or an activity with no rubric now
  reports "No records found" instead of raising PHP warnings or a database exception.
- Guarded the overall-grade lookup against an activity whose grade-item layout does not
  match the assumed offset.
- Removed three unused language strings (`config_scale`, `desc_scale`,
  `criterion_label_break`).
- Added PHPUnit regression coverage for the escaping and for invalid `activityid` handling.

## 1.405.02 (2026052802)

Removed dead code; added PHPUnit test suite.

- Deleted `classes/csv.php`. The `csv` class was made redundant by the 1.405.01 refactor to
  `flexible_table` downloads and was no longer called anywhere in the plugin.
- Added `tests/report_test.php` with six PHPUnit integration tests covering:
  - The `GRADABLES` constant structure;
  - Enrolled-user lookup (students found, teachers excluded);
  - Grading area SQL with and without a rubric area present;
  - Rubric criteria and max-score query;
  - `Rubricarray` structure built from the criteria/levels recordset;
  - And `display_table()` output for a student with no rubric fillings.

## 1.405.01 (2026052801)

Replaced manual CSV/Excel download code with `flexible_table`'s built-in download mechanism.

- Removed various separate CSV array-building paths due to flexible_table refactor.
- Maximum Moodle version raised to 5.2.

## 1.405.00 (2026052800)

Minimum Moodle version raised to 4.5. Requires Moodle 4.5 or later.

- Refactored to use accessible flexible_table class with download options.
- WCAG 2.2 AA data-table requirements met.

## Earlier releases

No prior CHANGES.md existed. Earlier version history is recorded in git.
