---
name: media
description: Postloom media library — use when changing uploads, the Media page, the media table, allowed file types or size limits (config postloom.media), video thumbnails and duration (ffmpeg / ffprobe via MediaInspector), duplicate detection, private file streaming, or when an upload fails ("too large", "does not look like a real … file", 413 / post too large, missing thumbnail or duration). Also the place to start when the post editor or a platform needs media files.
---

# Media library

KB: `knowledge-base/media/README.md`.

## Key files

- `app/Services/MediaService.php` (upload, duplicate check, store, delete)
- `app/Services/Media/MediaInspector.php` (GD for images, ffprobe and ffmpeg through `Process`)
- `app/Http/Requests/Media/StoreMediaRequest.php` (type, real content, size per kind)
- `app/Http/Controllers/MediaController.php`, `app/Policies/MediaPolicy.php`
- `app/Enums/MediaKind.php`, `config/postloom.php` → `media`
- `resources/js/pages/media/Index.vue`

## Debugging

| Symptom                                                                   | Check                                                                                                                                                                    |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Upload fails for files over 2 MB, or the request is rejected as too large | The web server was not started by `start.ps1` (for example `php artisan serve` by hand). Restart with `./stop.ps1` then `./start.ps1`                                    |
| "is larger than the N MB limit"                                           | Kind limit in `config/postloom.php` → `media.kinds.<kind>.max_kb`                                                                                                        |
| "does not look like a real … file"                                        | File content type doesn't match its extension (renamed file). Re-export it in the right format                                                                           |
| Video has no thumbnail or length                                          | ffmpeg/ffprobe missing or failed: look for `Media could not be inspected with ffprobe` / `Video thumbnail could not be created` and check `FFMPEG_PATH` / `FFPROBE_PATH` |
| Upload said "already in the library"                                      | Same file (same sha256) already exists in this workspace; the old row is reused                                                                                          |
| A deleted file came back after an upload                                  | Deletes are soft; uploading the same file restores the deleted row (log `Deleted media uploaded again, restored`). Files stay on disk after delete                       |

Logs: filter `storage/logs/postloom-*.log` by `extra.feature` = `media`, then by `media_id` or `workspace_id`.
