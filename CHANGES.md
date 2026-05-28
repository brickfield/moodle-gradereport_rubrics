# Changelog — gradereport_rubrics

## 1.405.01 (2026052801)

Replaced manual CSV/Excel download code with `flexible_table`'s built-in download mechanism.
This resolves a longstanding encoding bug where non-ASCII characters (accented names, non-Latin
scripts) were corrupted in downloaded files.

- Removed the separate CSV array-building path from `display_table()`. The method now has a
  single code path; `flexible_table` handles both HTML rendering and file downloads internally
  via its `is_downloading()` flag and `finish_output()`.
- Download format picker (CSV, ODS, Excel) is now rendered by `flexible_table` at the foot of
  the table, replacing the manual download links and help icon.
- Removed `$format`, `$excel`, and `$csv` properties from the `report` class and their
  corresponding constructor parameters — no longer needed.
- Removed `MoodleExcelWorkbook` and `csv_export_writer` imports from `report.php`.
- `index.php` simplified: `$format`, `$excel`, `$csv` local variables removed; all
  `if (!$csv)` guards removed; `$OUTPUT->footer()` is now unconditional.
- Removed unused lang strings: `csvdownload`, `excelcsvdownload`, `download`, `download_help`,
  `filename` — these were specific to the old manual download UI.
- Cell content uses `strip_tags()` on remarks in download mode to avoid raw HTML appearing
  in downloaded files.

## 1.405.00 (2026052800)

Minimum Moodle version raised to 4.5. Requires Moodle 4.5 or later.

- Replaced `html_table` / `html_table_row` / `html_table_cell` rendering in `display_table()` with
  Moodle's `flexible_table` class. The HTML report output now renders proper `<thead>`/`<tbody>`
  structure with `<th scope="col">` column headers and an accessible `summary` attribute, satisfying
  WCAG 2.2 AA data-table requirements.
- CSV and Excel download paths are unchanged — they continue to return a plain array and stream the
  file directly without involving `flexible_table`.
- `show()` now outputs directly throughout rather than accumulating a string; the redundant
  `echo $table` call in `index.php` has been removed accordingly.
- Fixed two mismatched `</il>` closing tags in the download-link list (should be `</li>`).
- `version.php`: bumped version to `2026052800`, release to `1.405.00`, raised `requires` to
  `2024100700` (Moodle 4.5), added `supported = [405, 501]`.

## Earlier releases

No prior CHANGES.md existed. Earlier version history is recorded in git.
