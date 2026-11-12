# CSV import

## Purpose

Create many posts at once from one CSV file, each **scheduled** or saved as a **draft**. The main use is the daily content pack: one video a day, with the same caption sent to YouTube, Facebook and TikTok. Every row goes through the same rules as the post editor (`PostService::save()` and `PostValidator`), so an imported post is the same as one made by hand.

## Flow

1. **Posts → Import CSV** (`/posts/import`). **Download template** gives a CSV with every column and two example rows that use this workspace's real account names.
2. Pick the CSV and, optionally, up to 10 media files, then **Check CSV** (`POST /posts/import/preview`):
    1. The media files go into the library with `MediaService::upload()` (duplicates are reused). Their uploaded file names are remembered, so the CSV can name them even if the library already had the same file under another name.
    2. The CSV is read (`CsvPostImporter::analyse()`). Structural problems (empty file, missing or unknown column, more than 200 rows) refuse the whole file with a message under the CSV field.
    3. The CSV text and the uploaded-media map are stored in the session under a new `import_id` (only the latest import is kept), and the browser goes to `/posts/import?import=<id>`.
3. The page reads and checks the stored CSV again and shows a **preview table**: row number (the header is row 1, like in Excel), status (Scheduled, Draft or Error), time in the row's timezone, matched accounts with platform icons, name, caption, media, and every error for that row in red. **Nothing is saved as a post yet.**
4. **Import N posts** (`POST /posts/import` with `import_id`) checks again and saves each valid row with `PostService::save()` (one transaction per row). Rows with errors are skipped. You land on Posts with "Imported 30 posts (28 scheduled, 2 drafts). 1 row skipped."

## Entry points

| Route                        | Name                    | Action                               |
| ---------------------------- | ----------------------- | ------------------------------------ |
| `GET /posts/import`          | `posts.import.create`   | upload form, preview when `?import=` |
| `GET /posts/import/template` | `posts.import.template` | CSV template download                |
| `POST /posts/import/preview` | `posts.import.preview`  | upload CSV + media, store, redirect  |
| `POST /posts/import`         | `posts.import.store`    | create the posts (`import_id`)       |

- `app/Http/Controllers/PostImportController.php`
- `app/Http/Requests/Posts/PreviewCsvImportRequest.php` (extends `StoreMediaRequest`, so media files get the same type, content and size checks)
- `app/Services/Posts/Import/CsvPostImporter.php`: `columns()`, `analyse()`, `preview()`, `import()`, `templateCsv()`
- `app/Services/Posts/Import/CsvRowParser.php`: one row → post data + errors (status, timezone, date/time, accounts, media, settings columns)
- `app/Services/Posts/Import/ImportRow.php`: one parsed row (`postData` in the `PostService::save()` shape, `errors`, `toPreview()`)
- Vue: `resources/js/pages/posts/Import.vue`; "Import CSV" button on `posts/Index.vue`

## Data

No new tables. Imported posts are normal `posts`, `post_targets` and `post_media` rows (see `knowledge-base/posts/README.md`). The pending import lives in the session key `csv_imports.<import_id>`: `workspace_id`, `file_name`, `csv`, `media` (lowercased uploaded name → media id).

## Rules

**Columns** (header row required, any order, case-insensitive; comma or semicolon separated; UTF-8 with or without BOM, Windows-1252 is converted):

| Column            | Required | Meaning                                                                           |
| ----------------- | -------- | --------------------------------------------------------------------------------- |
| `date`            | yes      | `YYYY-MM-DD` (or `YYYY/MM/DD`). Needed for scheduled rows                         |
| `time`            | yes      | 24-hour `HH:MM` (also `HH:MM:SS` and `6:00 PM`)                                   |
| `accounts`        | yes      | Separated by `;` or `\|`. See matching below                                      |
| `caption`         | yes      | Main text (may be empty if the row has media). Line breaks inside quotes are kept |
| `timezone`        | no       | e.g. `Asia/Karachi`. Default `POSTLOOM_TIMEZONE`                                  |
| `status`          | no       | `schedule` (default) or `draft`                                                   |
| `name`            | no       | Internal name (post title)                                                        |
| `media`           | no       | File names separated by `;`                                                       |
| `youtube_title`   | no       | Default: `name` (cut to 100 characters), like the editor's prefill                |
| `youtube_privacy` | no       | `public`, `unlisted`, `private`                                                   |
| `facebook_format` | no       | `post`, `reel`                                                                    |
| `tiktok_mode`     | no       | `manual` (default, "I'll post it myself"), `direct`, `inbox`                      |

Empty settings columns keep the platform defaults (`defaultSettings()`), so TikTok stays `manual`. Allowed values come from the platform rule classes (`YouTubePostRules::PRIVACY_OPTIONS`, `FacebookPostRules::FORMAT_OPTIONS`, `TikTokPostRules::MODE_OPTIONS`).

**Account matching** (only this workspace's accounts, case-insensitive):

- `Build Real Code` matches every account whose name or handle is that (YouTube channel and Facebook Page with the same name both match).
- `@buildrealcode` matches by handle only.
- `youtube:Build Real Code`, `tiktok:@buildrealcode`, `facebook:…`, `linkedin:…` narrow to one platform.
- The preview lists exactly which accounts matched. No match = error.

**Media matching:** the uploaded files first (by their uploaded name), then the library by `original_name` (the newest file wins when two have the same name). Not found = error.

**Checks per row:**

- CSV checks for every row: status, timezone, date and time format, accounts, media, settings values, caption up to 10,000 and name up to 255 characters. A scheduled row needs a date and time; a draft may leave both empty.
- Scheduled rows also run `PostValidator::scheduleErrors()` on an unsaved, in-memory `Post` (targets with defaults merged, media attached), so they get the editor's own messages: time in the past, text too long, YouTube title missing, Reel not vertical, TikTok needs a video, account needs reconnecting, and so on.
- **Duplicates:** a scheduled row is refused when one of its accounts already has a non-draft post at that exact time, or an earlier row in the same file uses that account at that time. This stops a pack being imported twice.
- Draft rows only get the CSV checks.

Limits: 200 rows and 1 MB per CSV, 10 media files per upload. For a month of videos, upload them on the Media page first (10 at a time), then import the CSV that names them.

## Logging

Feature `import`. Every line has `workspace_id` and `import_id`; one import keeps one `trace_id` (re-started with `LogService::startFeatureLogging(..., $traceId)` after each `PostService::save()` and media upload, which start their own `posts` / `media` runs).

| Message                               | Level   | Context                                             |
| ------------------------------------- | ------- | --------------------------------------------------- |
| `CSV import preview requested`        | info    | `size_bytes`, `media_files`                         |
| `CSV import preview ready`            | info    | `rows`, `valid`, `invalid`, `invalid_rows`          |
| `CSV import started`                  | info    | `rows`                                              |
| `CSV import row saved`                | info    | `row`, `post_id`, `status`                          |
| `CSV import row skipped`              | info    | `row`, `reasons` (warning when `save()` refused it) |
| `CSV import finished`                 | info    | `created`, `scheduled`, `drafts`, `skipped`         |
| `CSV import not found in the session` | warning | (expired or unknown `import_id`)                    |

Each saved row also writes the usual `posts` lines (`Post created`, `Post scheduled`) under its own trace; follow `post_id`.

## Config (`.env`)

- `POSTLOOM_TIMEZONE`: default timezone for rows without one.
- Media limits from `config/postloom.php` → `media`.

## Tests

`tests/Feature/Posts/CsvImportTest.php`: template download with real account names; daily pack preview saves nothing; daily pack import (UTC time, media, YouTube title from name, Facebook reel, TikTok manual); settings columns; account matching by name, @handle and prefix; row errors (unknown account, unknown media, bad date and time, bad timezone, bad TikTok mode, past time, missing YouTube title, LinkedIn text limit); drafts without time; scheduled row without time; duplicates in the database and in the file; valid rows imported and bad rows skipped with the toast counts; expired import id; other workspace's accounts and media never match; media uploaded with the CSV; semicolon CSV with BOM; missing and unknown columns; row limit; page props; guests redirected.

## Known limits

- Every row gets the same text on all its accounts (no per-account caption column). Edit the post afterwards for a different text.
- Settings columns cover YouTube title and privacy, Facebook format and TikTok mode. Other settings (LinkedIn visibility, YouTube tags, TikTok privacy) take their defaults; change them in the editor.
- Only the latest import is kept in the session; checking a second CSV replaces the first preview.
- Dates must be ISO (`YYYY-MM-DD`). If Excel turns them into `20/11/2026`, format the column as Text before saving.
- The import runs inside the web request (no queue). Fine for 200 rows.
