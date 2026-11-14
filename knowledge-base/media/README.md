# Media library

## Purpose

Each workspace has a library of images, videos, audio and PDFs. Files are uploaded once and picked in the post editor (piece 05). Files stay private on this PC and are only streamed to their owner.

## Flow

1. **Media page** (`/media`): drag files onto the drop zone or click "choose files". Up to 10 files per upload, with a progress bar.
   The same upload also runs from the **post editor** ("Add media" / "Change media" popup, and "Upload image" next to a YouTube thumbnail): the same upload, sent with Inertia `preserveState` + `only: ['library']`, so an unsaved post keeps everything. `MediaController@store` returns `back()` and flashes `uploaded_media_ids` (new and duplicate files), which the editor selects automatically (`resources/js/composables/useMediaUpload.ts`).
2. `StoreMediaRequest` checks each file: allowed extension, real content type matches the kind (`image/…`, `video/…`, `audio/…`, `application/pdf`), and size under that kind's limit.
3. `MediaService::upload()` per file:
    1. Works out the kind from the extension (`MediaKind::fromExtension`).
    2. Hashes the file (sha256). If the same file is already in this workspace, the existing row is reused and nothing is saved again. If it was deleted, that row is restored (`Deleted media uploaded again, restored`); if its old file is gone from disk, the new file is stored on that same row.
    3. Stores it as `media/<workspace_id>/<YYYY>/<MM>/<uuid>.<ext>` on the `POSTLOOM_MEDIA_DISK` disk (default `local` = `storage/app/private`).
    4. `MediaInspector` reads the details: images via GD `getimagesize`; video and audio via `ffprobe` (duration, and width and height for video).
    5. Videos get a 480px-wide JPG thumbnail from second 1 via `ffmpeg`, saved under `…/thumbs/<uuid>.jpg`.
    6. Saves the `media` row.
4. The grid shows thumbnails (images, and videos with a thumbnail), icons for audio and PDF, an audio player, size, dimensions and length. Filter tabs: All, Image, Video, Audio, Document. 48 per page.
5. Alt text can be added or edited (used later as the image description on platforms that support it). Delete is a soft delete: the row gets `deleted_at` and drops out of the library and of drafts; the file and thumbnail stay on disk so it can be restored.

If ffmpeg or ffprobe is missing or fails, the upload still succeeds; only duration, dimensions and thumbnail are empty (a warning is logged).

## Entry points

| Route                          | Name              | Action                                                          |
| ------------------------------ | ----------------- | --------------------------------------------------------------- |
| `GET /media`                   | `media.index`     | `MediaController@index` (`?kind=image\|video\|audio\|document`) |
| `POST /media`                  | `media.store`     | `MediaController@store` (`files[]`)                             |
| `GET /media/{media}/file`      | `media.show`      | streams the original file                                       |
| `GET /media/{media}/thumbnail` | `media.thumbnail` | streams the thumbnail (or the image itself)                     |
| `PATCH /media/{media}`         | `media.update`    | alt text                                                        |
| `DELETE /media/{media}`        | `media.destroy`   | delete                                                          |

- `app/Services/MediaService.php`, `app/Services/Media/MediaInspector.php`
- `app/Http/Requests/Media/StoreMediaRequest.php`, `UpdateMediaRequest.php`
- `app/Policies/MediaPolicy.php` (view, update, delete: owner of the workspace only)
- `app/Enums/MediaKind.php`
- `resources/js/pages/media/Index.vue`

## Data

**`media`**: `workspace_id`, `kind`, `disk`, `path`, `original_name`, `mime_type`, `size_bytes`, `width`, `height`, `duration_seconds`, `thumbnail_path`, `checksum` (sha256), `alt_text`, timestamps. Unique: `workspace_id + checksum`.

## Rules

| Kind     | Extensions                | Max size |
| -------- | ------------------------- | -------- |
| image    | jpg, jpeg, png, gif, webp | 20 MB    |
| video    | mp4, mov                  | 500 MB   |
| audio    | mp3, m4a, wav             | 100 MB   |
| document | pdf                       | 100 MB   |

- Limits live in `config/postloom.php` → `media.kinds`; max 10 files per upload (`media.max_files_per_upload`).
- Files are never public. `media.show` and `media.thumbnail` check `MediaPolicy::view`. The one exception is Instagram (piece 19): a signed, 15-minute `public-media.show` link to a single photo or video, reachable through the Cloudflare tunnel only for that route (`knowledge-base/instagram/README.md`).
- Platform limits are checked by the platform when posting, not here (see `knowledge-base/linkedin/README.md` → Publishing). LinkedIn: video 3 s–30 min and up to 500 MB, documents up to 100 MB / 300 pages; it has no audio posts, so audio is turned into a video there.
- **Upload size on this PC:** Herd's `php.ini` allows 2 MB per file. `start.ps1` runs the web server as `php -d upload_max_filesize=512M -d post_max_size=520M -d memory_limit=512M -S localhost:8000 -t public …/server.php`, so the bigger limits apply to Postloom only. Running `php artisan serve` by hand brings back the 2 MB limit.

## Logging

Feature `media`.

| Message                                     | Level   | Context                                           |
| ------------------------------------------- | ------- | ------------------------------------------------- |
| `Media upload received`                     | info    | `workspace_id`, `kind`, `extension`, `size_bytes` |
| `Media already in library, reused`          | info    | `media_id`                                        |
| `Media stored`                              | info    | `media_id`, `kind`, `path`, `has_thumbnail`       |
| `Media file could not be saved to disk`     | error   | `disk`, `directory` (the upload then fails)       |
| `Media could not be inspected with ffprobe` | warning | `exit_code`, `error` (last 500 chars)             |
| `Video thumbnail could not be created`      | warning | `exit_code`, `error`                              |
| `Media alt text updated`                    | info    | `media_id`                                        |
| `Media deleted`                             | info    | `media_id`                                        |
| `Deleted media uploaded again, restored`    | info    | `media_id`                                        |

## Config (`.env`)

- `POSTLOOM_MEDIA_DISK=local`
- `FFMPEG_PATH=ffmpeg`, `FFPROBE_PATH=ffprobe` (full paths if they are not on PATH; on this PC they come from the winget `Gyan.FFmpeg.Shared` package and are on PATH)

## Tests

`tests/Feature/Media/MediaLibraryTest.php` (fake `local` disk; ffprobe and ffmpeg faked with `Process::fake`): image with size; video with duration, size and thumbnail; audio and PDF; upload works without ffmpeg; unsupported type; content not matching extension; over the size limit; more than 10 files; duplicate reused; current workspace only and kind filter; file served only to the owner; guests redirected; alt text; delete soft deletes the row and keeps the file and thumbnail; uploading a deleted file again restores it (also when its file is gone); other owner blocked.

The inspector was also checked once against the real ffmpeg 9 on this PC (3-second 640×360 test video: duration, size and thumbnail all correct).

## Known limits

- One request per upload batch, so very large videos depend on the 512 MB limit. Chunked uploads would be needed for bigger files or a production server.
- No image editing or cropping.
- Media used by a scheduled or publishing post cannot be deleted (`MediaService::deletionBlockReason()`), nor media used by a failed or partly published post that still has failed accounts ("Retry or delete that post first"); media used only in drafts or delivered posts is deleted and removed from them. See `knowledge-base/posts/README.md`.
