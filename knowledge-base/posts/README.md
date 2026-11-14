# Posts and the post editor

## Purpose

Write a post once, choose which accounts it goes to, attach media from the library, adjust text and settings per account, preview it, and save it as a **draft** or **schedule** it. Publishing itself is pieces 07 (scheduler) and 08 (LinkedIn); this piece prepares and validates everything they need.

## Flow

1. **Posts** (`/posts`) lists the workspace's posts (scheduled first by time, then the rest), with status filter tabs, time in the post's own timezone, account avatars and media count. Edit / View, Duplicate, Delete.
2. **New post** (`/posts/create`) or **Edit** (`/posts/{id}/edit`):
    1. **Accounts:** tick any connected accounts in this workspace (several per platform allowed).
    2. **Text:** main text with a live counter against the strictest chosen platform's limit.
    3. **Media:** "Add media from library" opens a picker over the workspace library (latest 200). Selection order = display order; reorder with the arrows, remove with ×.
    4. **Per account:** "Customize" gives an optional different text and the platform's settings (LinkedIn: who can see it).
    5. **When:** `ScheduleTimePicker`: a calendar date picker (`DatePicker.vue`: month grid with ‹ › navigation, past days disabled, today outlined, Today and Tomorrow shortcuts; no extra package) and a time field (minute precision; on today, times before the next minute are blocked), quick buttons +15 min, +30 min, +1 hour, +4 hours and Tomorrow 9:00 AM, and a live hint ("Goes out in 1 h 5 min" or "This time has passed"). All in the chosen timezone (default `POSTLOOM_TIMEZONE`, Asia/Karachi), using `resources/js/lib/zonedTime.ts`. Any future minute today is allowed; the server still refuses the past. Internal name is optional.
    6. **Preview:** one LinkedIn-style card per chosen account, using that account's text, media and visibility.
3. **Save draft** saves as `draft` with no checks beyond the form. **Schedule** saves and runs `PostValidator::scheduleErrors()`; if anything fails, the whole save is rolled back and the reasons appear at the top of the form.
4. **Move to drafts** turns a scheduled post back into a draft. **Duplicate** makes a draft copy with no time. Saving a scheduled post again with **Save draft** also makes it a draft.

## Entry points

| Route                           | Name               | Action                          |
| ------------------------------- | ------------------ | ------------------------------- |
| `GET /posts`                    | `posts.index`      | list (`?status=`)               |
| `GET /posts/create`             | `posts.create`     | editor                          |
| `POST /posts`                   | `posts.store`      | save (`intent=draft\|schedule`) |
| `GET /posts/{post}/edit`        | `posts.edit`       | editor                          |
| `PUT /posts/{post}`             | `posts.update`     | save                            |
| `POST /posts/{post}/unschedule` | `posts.unschedule` | back to draft                   |
| `POST /posts/{post}/duplicate`  | `posts.duplicate`  | draft copy                      |
| `DELETE /posts/{post}`          | `posts.destroy`    | delete                          |

- `app/Services/Posts/PostService.php`: `save()`, `unschedule()`, `duplicate()`, `delete()`
- `app/Services/Posts/PostValidator.php`: `scheduleErrors()`
- `app/Services/Posts/Rules/PlatformPostRules.php` (contract), `LinkedInPostRules.php`, `PlatformRulesRegistry.php`
- `app/Http/Requests/Posts/SavePostRequest.php` (also checks ownership first, so another owner's post gives 403)
- `app/Http/Controllers/PostController.php`, `app/Policies/PostPolicy.php`
- Vue: `resources/js/pages/posts/Index.vue`, `posts/Form.vue`, `components/posts/MediaPicker.vue`, `components/posts/PostPreview.vue`, `components/posts/ScheduleTimePicker.vue`, `components/posts/DatePicker.vue`, `lib/zonedTime.ts`, types in `resources/js/types/posts.ts`

## Data

| Table          | Columns                                                                                                                                                                                                                                                                                  |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `posts`        | `workspace_id`, `user_id`, `title`, `content`, `status` (`PostStatus`), `scheduled_at` (**UTC**), `timezone`, timestamps                                                                                                                                                                 |
| `post_targets` | `post_id`, `social_account_id` (unique per post), `content_override`, `settings` (JSON, defaults from the platform rules), `status` (`PostTargetStatus`: pending, publishing, published, ready_to_post, failed), `attempts`, `last_error`, `external_id`, `external_url`, `published_at` |
| `post_media`   | `post_id`, `media_id` (unique per post), `sort_order`                                                                                                                                                                                                                                    |

`PostStatus`: draft, scheduled, publishing, published, partly_published, failed. `upcoming()` = scheduled + publishing.

**What can change (fix 20):**

| Status                      | Edit / schedule / move to drafts | Delete | Editor                                   |
| --------------------------- | -------------------------------- | ------ | ---------------------------------------- |
| draft, scheduled, failed    | yes                              | yes    | normal                                   |
| publishing                  | no                               | no     | read-only, "being published right now"   |
| published, partly_published | no                               | yes    | read-only notice + **Duplicate to edit** |

`PostStatus::isEditable()`, `isDeletable()`, `isDelivered()`; the editor gets `is_editable`, `is_deletable`, `is_delivered`. A post that already went out is a record of what was posted: platforms don't let us change it, and saving it used to reset it to draft with every account pending, so Schedule posted it a second time. Partly published posts still use **Retry failed accounts** for the accounts that failed. A refused change logs `Post change refused` (warning, `status`).

## Rules

**Scheduling needs** (`PostValidator`):

- at least one account (`targets`)
- a date and time in the future (`scheduled_at`)
- some text or media (`content`)
- for every account (`targets.<account id>`): the platform supports posting (rules class exists), the account doesn't need reconnecting, the text (custom or main) is within the limit, the media fit the platform, and the settings are valid.

**LinkedIn** (`LinkedInPostRules`): text up to 3,000 characters; up to 20 images, or 1 video, or 1 PDF, or 1 audio file (posted as a video in piece 08), with no mixing; visibility `PUBLIC` (Anyone) or `CONNECTIONS`.

**Facebook** (`FacebookPostRules`, piece 14): text up to 63,206 characters; text only, up to 10 photos (JPG, PNG, GIF, BMP, TIFF; 10 MB each), 1 video or 1 audio file; no PDFs, no mixing. Settings: `format` (`post` / `reel`), `video_title`. A Reel needs one vertical 9:16 video of 3–90 seconds.

**TikTok** (`TikTokPostRules`, piece 17): caption up to 2,200 characters; exactly 1 video (MP4, MOV or WebM, 3 s to 10 min, up to 4 GB) or 1 audio file (posted as a video); no text-only posts, photos or PDFs (manual mode: video only, no audio). Settings: `mode` (`manual` default: you upload it yourself from **To post** / `direct` / `inbox`), `privacy` (`SELF_ONLY` default, `MUTUAL_FOLLOW_FRIENDS`, `FOLLOWER_OF_CREATOR`, `PUBLIC_TO_EVERYONE`; used for direct posts), `allow_comments`, `allow_duet`, `allow_stitch` (all off by default), `ai_generated`, `commercial` (`none` / `your_brand` / `branded`). `targetErrors()` refuses branded content posted directly as Only me. The preview is a vertical card with an "Inbox draft" or privacy badge.

**Instagram** (`InstagramPostRules`, piece 19): caption up to 2,200 characters, 30 hashtags, 20 @mentions; needs a photo or video (no text-only, no PDFs); photos JPG or PNG up to 8 MB; up to 10 items. Settings: `format` (`post`: 1 photo or a carousel of 2–10 photos/videos, feed photos 4:5 to 1.91:1, a single video must use Reel / `reel`: 1 video or audio, 3 s to 15 min, up to 300 MB / `story`: 1 photo or a 3–60 s video), `share_to_feed` (Reels, default on). The preview is a square feed card with carousel dots (caption cut at 125 characters), or a vertical card for Reels and Stories. Details: `knowledge-base/instagram/README.md`.

**YouTube** (`YouTubePostRules`, piece 11): exactly 1 video or 1 audio file (no images or PDFs; the thumbnail is a setting). Description = the text, up to 5,000 bytes. Settings: `title` (required, up to 100, no `<` `>`), `format` (`auto`/`short`/`long`), `privacy` (`public`/`unlisted`/`private`), `category` (YouTube id, default `22`), `tags` (comma separated, up to 500 characters counted YouTube's way), `thumbnail_media_id` (an image from the library), `made_for_kids`, `synthetic_media`, `notify_subscribers`. `targetErrors()` checks bytes, tags, a title that is just the tags (blocked), the thumbnail and Short limits (vertical or square, up to 3 minutes). The editor prefills the title with the internal name. Settings can carry a `help` line shown under the field (for example "Made for kids turns off comments and notifications").

**Time:** the form sends local time + timezone; `PostService` converts it to UTC for `scheduled_at`, and the `Post::scheduledAt()` mutator also forces UTC on every write (see `knowledge-base/calendar/README.md`). The editor and list convert back with the post's own timezone.

**In-use guards (approved 2026-11-10):**

- Disconnecting an account with a scheduled or publishing post is blocked (`SocialAccountService::disconnectBlockReason()`). Used only in drafts: it's disconnected and removed from those drafts.
- Deleting media used by a scheduled or publishing post is blocked (`MediaService::deletionBlockReason()`), and so is media used by a failed or partly published post that still has failed accounts (a retry would post without it). Used only in drafts or delivered posts: it's deleted and removed from them.
- Save and unschedule lock the post row and check its status inside the transaction, so a post the scheduler took a moment ago is refused ("being published right now") instead of being overwritten.

**Adding a platform:** add a class implementing `PlatformPostRules`, return it from `PlatformRulesRegistry::forProvider()`. The editor (counter, media hint, settings fields, validation) picks it up from `describe()`. Each setting has a `type` that `resources/js/components/posts/PlatformSettings.vue` renders: `select` (with `options`), `text` (`max_length`, `required`), `tags`, `boolean`, `image` (pick from the library). Settings are shown under each account in "Per account" (no need to open Customize). Checks that need text, media and settings together go in `targetErrors()`.

## Logging

Feature `posts`; context has `workspace_id` and `post_id`.

| Message                         | Level   | Context                          |
| ------------------------------- | ------- | -------------------------------- |
| `Post created` / `Post updated` | info    | `targets`, `media` (counts)      |
| `Post scheduled`                | info    | `scheduled_at` (UTC), `timezone` |
| `Post could not be scheduled`   | warning | `reasons` (list)                 |
| `Post moved back to draft`      | info    |                                  |
| `Post duplicated`               | info    | `source_post_id`                 |
| `Post deleted`                  | info    |                                  |
| `Post change refused`           | warning | `status`                         |

## Config (`.env`)

- `POSTLOOM_TIMEZONE` (default timezone of new posts)

## Tests

- `tests/Feature/Posts/PostEditorTest.php`: same-day times (1 minute, 20 minutes, 4 hours later accepted; 1 minute ago refused); draft with targets, ordered media and custom text; UTC storage and local display; schedule success message; refused schedules (no account, past time, no time, no text or media) save nothing; text-limit message; LinkedIn media rules (mix, 2 videos, 2 PDFs, 21 images) and 20 images allowed; reconnect-needed and not-yet-supported accounts; other workspace's accounts and media rejected; update drops removed accounts; back to draft; publishing post locked; published / partly published posts refuse edits and unschedule but can be deleted; read-only editor props; failed post can be edited and rescheduled; duplicate; delete; another owner gets 403; list and filter; editor props.
- `tests/Feature/Posts/InUseGuardsTest.php`: the two in-use guards, blocked and allowed.

## Known limits

- The media picker shows the latest 200 files; there is no search yet.
- Publish now and Retry failed accounts are covered in `knowledge-base/scheduler/README.md`.
- Many posts at once from a CSV (**Import CSV** on the Posts page, piece 18) are covered in `knowledge-base/csv-import/README.md`. Every row goes through `PostService::save()` and `PostValidator`, so the rules here apply to imported posts too.
- The LinkedIn preview is close to LinkedIn's layout, not pixel-perfect. "see more" is cut at 210 characters.
