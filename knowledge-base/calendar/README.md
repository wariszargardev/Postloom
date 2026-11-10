# Calendar

## Purpose

See every post with a time on a month, week or day calendar. Click an empty time to start a post there, click a post to edit it, and drag a draft or scheduled post to move it. The calendar is the first page after login.

## Flow

1. `/calendar` renders `calendar/Index.vue` with FullCalendar 6.1.22 (month = `dayGridMonth`, week = `timeGridWeek` (default), day = `timeGridDay`). Week starts on Monday; 30-minute rows; the red "now" line follows the calendar timezone.
2. FullCalendar asks `GET /calendar/events?start=…&end=…` for the visible range. The controller converts the range from calendar wall-clock time to UTC, loads the current workspace's posts with a `scheduled_at` in it, and returns each post's time as **wall-clock time in `POSTLOOM_TIMEZONE`** (`2026-11-13T09:30:00`, no offset).
3. **Timezone trick:** FullCalendar runs with `timeZone: 'UTC'` and those naive times, so it shows exactly the Karachi wall-clock time without a timezone plugin. Posts saved in another timezone appear at the matching Karachi time. The header says "Times in Asia/Karachi".
4. **Click an empty slot** → `/posts/create?scheduled_at=YYYY-MM-DDTHH:mm`, and the editor's date and time are prefilled. In month view a day click uses 09:00, or the next minute if it's today. Past slots show "That time has already passed".
5. **Click a post** → its editor.
6. **Drag a post** → `PATCH /posts/{post}/reschedule` with the new wall time. `PostService::reschedule()` converts it to UTC and keeps the status (a scheduled post stays scheduled). A past time or a non-movable post is refused and the event snaps back with a toast.

## Entry points

| Route                            | Name               | Action                                           |
| -------------------------------- | ------------------ | ------------------------------------------------ |
| `GET /calendar`                  | `calendar.index`   | page                                             |
| `GET /calendar/events`           | `calendar.events`  | JSON events (`start`, `end` required)            |
| `PATCH /posts/{post}/reschedule` | `posts.reschedule` | move (`scheduled_at` = `Y-m-d\TH:i:s` wall time) |

- `app/Http/Controllers/CalendarController.php`
- `app/Http/Requests/Posts/ReschedulePostRequest.php` (owner check first, then format)
- `app/Services/Posts/PostService.php` → `reschedule()`
- `app/Enums/PostStatus.php` → `isMovable()`, `calendarColor()`
- `app/Models/Post.php` → `scheduledAt()` mutator (always stores UTC)
- Vue: `resources/js/pages/calendar/Index.vue`, `resources/js/components/calendar/PostEventContent.vue`
- `config/fortify.php` → `home` = `/calendar`

## Data

No new tables. Uses `posts.scheduled_at` (UTC) and `posts.status`. Event JSON: `id`, `title` (internal name, or the first 60 characters of the text), `start`, `editable`, `classNames`, `extendedProps` (`status`, `status_label`, `edit_url`, `accounts` with name and avatar).

## Rules

- Only `draft` and `scheduled` posts can be dragged (`PostStatus::isMovable()`). Publishing, published, partly published and failed posts can't be dragged.
- Moving needs a future time.
- Colours: scheduled = blue, draft = grey dashed, publishing = amber, published = green, failed or partly published = red.
- `Post::scheduled_at` is always stored in UTC by the model mutator. Eloquent otherwise saves a Carbon's wall-clock time and ignores its timezone (found while building this piece; covered by a test).

## Logging

Feature `calendar`; context has `workspace_id` and `post_id`.

| Message              | Level   | Context                          |
| -------------------- | ------- | -------------------------------- |
| `Post rescheduled`   | info    | `from`, `to` (UTC ISO), `status` |
| `Reschedule refused` | warning | `reason`, `requested_at`         |

## Config (`.env`)

- `POSTLOOM_TIMEZONE`: the timezone the whole calendar is shown in.

## Tests

`tests/Feature/Calendar/CalendarTest.php`: page and timezone prop; events in range as Karachi wall time; a London-timezone post shown at the Karachi time; out-of-range, unscheduled and other-workspace posts left out; published posts not draggable; move keeps scheduled status and converts to UTC; past time refused; publishing and published refused; other owner 403; editor prefill (valid and invalid); login lands on the calendar; `scheduled_at` stored in UTC whatever timezone it's given in.

## Known limits

- Unscheduled drafts are not on the calendar (they have no time). Dragging them in from a side list could come later.
- No per-account preferred posting times yet.
- Event length is fixed at 30 minutes (posts have no duration).
