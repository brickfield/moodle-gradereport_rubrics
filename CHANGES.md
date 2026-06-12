# Changelog — gradereport_rubrics

## 1.405.03 (2026061101)

Updated README.md.

## 1.405.02 (2026061100)

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
