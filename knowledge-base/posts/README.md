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
    5. **When:** `ScheduleTimePicker`: a date field (no past days) and a time field (minute precision; on today, times before the next minute are blocked), quick buttons +15 min, +30 min, +1 hour, +4 hours and Tomorrow 9:00 AM, and a live hint ("Goes out in 1 h 5 min" or "This time has passed"). All in the chosen timezone (default `POSTLOOM_TIMEZONE`, Asia/Karachi), using `resources/js/lib/zonedTime.ts`. Any future minute today is allowed; the server still refuses the past. Internal name is optional.
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
- Vue: `resources/js/pages/posts/Index.vue`, `posts/Form.vue`, `components/posts/MediaPicker.vue`, `components/posts/PostPreview.vue`, types in `resources/js/types/posts.ts`

## Data

| Table          | Columns                                                                                                                                                                                                                                                                   |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `posts`        | `workspace_id`, `user_id`, `title`, `content`, `status` (`PostStatus`), `scheduled_at` (**UTC**), `timezone`, timestamps                                                                                                                                                  |
| `post_targets` | `post_id`, `social_account_id` (unique per post), `content_override`, `settings` (JSON, defaults from the platform rules), `status` (`PostTargetStatus`: pending, publishing, published, failed), `attempts`, `last_error`, `external_id`, `external_url`, `published_at` |
| `post_media`   | `post_id`, `media_id` (unique per post), `sort_order`                                                                                                                                                                                                                     |

`PostStatus`: draft, scheduled, publishing, published, partly_published, failed. Only `publishing` blocks edits and deletes. `upcoming()` = scheduled + publishing.

## Rules

**Scheduling needs** (`PostValidator`):

- at least one account (`targets`)
- a date and time in the future (`scheduled_at`)
- some text or media (`content`)
- for every account (`targets.<account id>`): the platform supports posting (rules class exists), the account doesn't need reconnecting, the text (custom or main) is within the limit, the media fit the platform, and the settings are valid.

**LinkedIn** (`LinkedInPostRules`): text up to 3,000 characters; up to 20 images, or 1 video, or 1 PDF, or 1 audio file (posted as a video in piece 08), with no mixing; visibility `PUBLIC` (Anyone) or `CONNECTIONS`.

**Time:** the form sends local time + timezone; `PostService` converts it to UTC for `scheduled_at`. The editor and list convert back with the post's own timezone.

**In-use guards (approved 2026-11-10):**

- Disconnecting an account with a scheduled or publishing post is blocked (`SocialAccountService::disconnectBlockReason()`). Used only in drafts: it's disconnected and removed from those drafts.
- Deleting media used by a scheduled or publishing post is blocked (`MediaService::deletionBlockReason()`). Used only in drafts: it's deleted and removed from those drafts.

**Adding a platform:** add a class implementing `PlatformPostRules`, return it from `PlatformRulesRegistry::forProvider()`. The editor (counter, media hint, settings fields, validation) picks it up from `describe()`.

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

## Config (`.env`)

- `POSTLOOM_TIMEZONE` (default timezone of new posts)

## Tests

- `tests/Feature/Posts/PostEditorTest.php`: same-day times (1 minute, 20 minutes, 4 hours later accepted; 1 minute ago refused); draft with targets, ordered media and custom text; UTC storage and local display; schedule success message; refused schedules (no account, past time, no time, no text or media) save nothing; text-limit message; LinkedIn media rules (mix, 2 videos, 2 PDFs, 21 images) and 20 images allowed; reconnect-needed and not-yet-supported accounts; other workspace's accounts and media rejected; update drops removed accounts; back to draft; publishing post locked; duplicate; delete; another owner gets 403; list and filter; editor props.
- `tests/Feature/Posts/InUseGuardsTest.php`: the two in-use guards, blocked and allowed.

## Known limits

- The media picker shows the latest 200 files; there is no search yet.
- No "Post now" button yet; that comes with the scheduler (piece 07).
- The LinkedIn preview is close to LinkedIn's layout, not pixel-perfect. "see more" is cut at 210 characters.
