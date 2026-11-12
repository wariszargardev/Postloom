---
name: csv-import
description: Postloom CSV import — use when changing Posts → Import CSV (/posts/import), the CSV template, CsvPostImporter, CsvRowParser, ImportRow, PreviewCsvImportRequest or PostImportController, adding a CSV column or a per-platform settings column (youtube_title, youtube_privacy, facebook_format, tiktok_mode), account or media matching, duplicate checks, or the daily content pack. Also for "row has errors", "No account called …", "Media … is not in the library", "already has a post at this time", "Missing column(s)", "Unknown column(s)", "This import expired", Excel date problems, or posts missing after an import.
---

# CSV import

KB: `knowledge-base/csv-import/README.md`. Posts and editor rules: `knowledge-base/posts/README.md`.

## Key files

- `app/Services/Posts/Import/CsvPostImporter.php` (read CSV, analyse, preview, import, template)
- `app/Services/Posts/Import/CsvRowParser.php` (one row: status, timezone, date/time, accounts, media, settings columns)
- `app/Services/Posts/Import/ImportRow.php`
- `app/Http/Controllers/PostImportController.php`, `app/Http/Requests/Posts/PreviewCsvImportRequest.php`
- `resources/js/pages/posts/Import.vue`
- Tests: `tests/Feature/Posts/CsvImportTest.php`

## Adding a settings column

Add it to `CsvRowParser::settingColumnRules()` (column → provider, setting key, allowed values from the platform rules class). The header check, template, page help and preview pick it up. Add a test and a row to the KB columns table.

## Debugging

| Symptom                           | Check                                                                                              |
| --------------------------------- | -------------------------------------------------------------------------------------------------- |
| "No account called …"             | Name/handle must match an account in the current workspace; use `youtube:Name` or `tiktok:@handle` |
| Wrong accounts matched            | A plain name matches every platform with that name; add the `platform:` prefix                     |
| "Media … is not in the library"   | Name must equal the library file's original name (any case) or a file uploaded with the CSV        |
| Date "is not valid"               | Excel changed the date format; format the column as Text and use YYYY-MM-DD                        |
| "already has a post at this time" | A non-draft post for that account exists at that minute (the pack was already imported)            |
| "This import expired"             | Session lost or another CSV was checked after it; check the CSV again                              |
| Row error from the editor rules   | Same message as the editor's schedule check (`PostValidator`); see the posts / platform skills     |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `import`, then `extra.import_id`; each saved row has `post_id`, follow it into the `posts` feature lines.
