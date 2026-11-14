---
name: scheduler
description: Postloom scheduler and publish queue — use when changing posts:dispatch-due, PostDispatcher, PublishPostTargetJob, retries, PostStatusResolver, publish_attempts, Publish now / Retry failed accounts, the delivery panel, or when adding a platform Publisher. Also for "post didn't go out", "posted twice", "stuck in publishing", "missed: Postloom was off", "timed out", "partly published", retries not happening, or POSTLOOM_PUBLISHING_ENABLED / catch-up / stuck settings.
---

# Scheduler and publish queue

KB: `knowledge-base/scheduler/README.md`.

## Key files

- `app/Console/Commands/DispatchDuePosts.php`, `routes/console.php`
- `app/Services/Publishing/PostDispatcher.php`, `PostStatusResolver.php`
- `app/Jobs/PublishPostTargetJob.php`
- `app/Services/Publishing/Publisher.php`, `PublishResult.php`, `PublishException.php`, `PublisherRegistry.php`, registration in `app/Providers/AppServiceProvider.php`
- `app/Models/PublishAttempt.php`

## Debugging a post that didn't go out

1. Is `start.ps1` running (scheduler and queue windows)? Is `POSTLOOM_PUBLISHING_ENABLED=true`?
2. Run `php artisan posts:dispatch-due` by hand; it prints dispatched / missed / timed out.
3. Post status `scheduled` and time passed → the scheduler isn't running. `publishing` → jobs are waiting: is the queue worker running? `failed` → open the post: the Delivery panel shows each account's error and every try.
4. Logs: `storage/logs/postloom-*.log`, `extra.feature` = `scheduler` or `publishing`, filter by `extra.post_id`, then follow `trace_id`.
5. Raw history: table `publish_attempts` by `post_target_id`.

## Rules not to break

- Keep the `scheduled → publishing` conditional update as the lock, and the "skip published accounts" check in the job.
- Publishers throw `PublishException::retryable()` only for temporary problems, and never for a dropped connection or 5xx on the call that makes the post public (it may already be posted).
- Timed out = the account had no job left in the `jobs` table for 30 minutes. A retry waiting in the queue is not timed out.
- Retry re-checks the failed accounts (`PostValidator::retryErrors()`); log `Retry refused` lists why.
- `DB_QUEUE_RETRY_AFTER` must stay above the job `timeout`.
