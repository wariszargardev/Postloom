---
name: calendar
description: Postloom calendar — use when changing the Calendar page (FullCalendar 6 month/week/day), the calendar events feed, click-to-create or drag-to-reschedule, event colours, the after-login landing page, or when a post shows at the wrong time on the calendar, won't drag, snaps back, or the calendar is empty. Covers CalendarController, PostService::reschedule, PostStatus::isMovable / calendarColor, the UTC wall-clock trick and the Post scheduled_at UTC mutator.
---

# Calendar

KB: `knowledge-base/calendar/README.md`.

## Key files

- `app/Http/Controllers/CalendarController.php` (`index`, `events`, `reschedule`)
- `app/Http/Requests/Posts/ReschedulePostRequest.php`
- `app/Services/Posts/PostService.php` → `reschedule()`
- `app/Enums/PostStatus.php` → `isMovable()`, `calendarColor()`
- `resources/js/pages/calendar/Index.vue`, `resources/js/components/calendar/PostEventContent.vue`

## How times work

FullCalendar runs with `timeZone: 'UTC'`. The server sends each post's time as wall-clock time in `POSTLOOM_TIMEZONE` with no offset, and the browser reads a dropped event's UTC parts back as that wall time. Never send real UTC times to the calendar, and never add a timezone plugin without changing both sides.

## Debugging

| Symptom                | Check                                                                                                                                                        |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Post at the wrong hour | Raw `posts.scheduled_at` must be UTC. The `scheduledAt()` mutator enforces it; check any code writing the column with a query builder (bypasses the mutator) |
| Can't drag a post      | Only draft and scheduled are movable                                                                                                                         |
| Drag snaps back        | Toast shows the reason; log `Reschedule refused` with `reason`                                                                                               |
| Calendar empty         | Open `/calendar/events?start=…&end=…` in the browser; posts must belong to the current workspace and have a time                                             |

Logs: filter `storage/logs/postloom-*.log` by `extra.feature` = `calendar`.
