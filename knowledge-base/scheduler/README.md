# Scheduler and publish queue

## Purpose

When a scheduled post's time comes, send it to each of its accounts **exactly once**, retry temporary failures, record every try, and set the post's final status. The actual platform call lives in a `Publisher` per platform (LinkedIn: piece 08). This piece is the engine around it.

## Flow

1. `start.ps1` runs `php artisan schedule:work`, which runs **`posts:dispatch-due` every minute** (without overlapping), and `php artisan queue:work`, which runs the jobs.
2. `PostDispatcher::dispatchDue()`:
    1. If `POSTLOOM_PUBLISHING_ENABLED` is false: logs it and stops. Scheduled posts simply wait.
    2. **Stuck check:** accounts of a `publishing` post that are still `pending` or `publishing`, haven't changed for `POSTLOOM_STUCK_MINUTES` (30) and have **no job left in the queue** (`QueuedPublishJobs` reads the `jobs` table) are failed with "Timed out", and their post's status is resolved. A retry waiting in a long queue is not stuck; a lost job (pending forever) is. Other queue drivers can't be read, so there only the 30 minutes count.
    3. Finds `scheduled` posts with `scheduled_at <= now`, oldest first.
    4. **Missed:** if a post is more than `POSTLOOM_CATCH_UP_HOURS` (6) late (the PC was off), it is **not sent**. Its waiting accounts get "Missed: Postloom was off at the scheduled time…" and `PostStatusResolver` sets the post: `failed`, or `partly_published` when an earlier run already reached an account.
    5. Otherwise **`dispatchPost()`**: one conditional update `scheduled → publishing` (the lock; if another run already took it, nothing happens), then one **`PublishPostTargetJob`** per `pending` account.
3. `PublishPostTargetJob` (3 tries, timeout 540 s, unique per account for 15 min):
    1. Skips if the post isn't publishing anymore or the account is already published or ready_to_post (so an account is never sent twice).
    2. Sets the account to `publishing`, increments `attempts`, and creates a `publish_attempts` row (`running`).
    3. Fails permanently if the account needs reconnecting, or if no publisher exists for its platform.
    4. Calls `Publisher::publish()`. Success: saves `external_id`, `external_url`, `published_at` and the account's `last_used_at`. If the result has `needsManualPost` (TikTok "I'll post it myself"), the account becomes **`ready_to_post`** ("Ready for you", amber) instead, with no `published_at`; it waits under **To post** until the owner clicks Mark as posted (see `knowledge-base/tiktok/README.md` → Manual hand-off).
    5. `PublishException::retryable()`, or any unexpected error: retried after **60 s**, then **300 s**. After the 3rd try, failed for good. `PublishException::permanent()`: failed at once.
    6. Every try updates its `publish_attempts` row (status, error, duration).
4. `PostStatusResolver::resolve()`: when no account is pending or publishing, the post becomes **published** (all OK) (`ready_to_post` counts as OK), **failed** (none OK) or **partly published** (some OK).
5. **Publish now** (editor): intent `publish_now` saves and schedules the post for the next minute, then calls `dispatchPost()` straight away. If publishing is off, it stays scheduled.
6. **Retry failed accounts** (editor, for failed or partly published posts): the failed accounts are checked again first (`PostValidator::retryErrors()`: the same checks as scheduling, except the time; a reconnect-needed account or broken media refuses the retry and logs `Retry refused`). Then failed accounts go back to `pending`, the post becomes `scheduled` (time = now unless it is still in the future), then it is dispatched. Published accounts are not sent again.
7. **Races:** save, unschedule and retry lock the post row (`lockForUpdate`) and check its status inside the transaction, so they can't overwrite a post the scheduler has just taken. The scheduler only takes a post that is still `scheduled` and still due (`dispatchPost($post, $dueBy)`), so a post moved to a later time in the same moment waits.

## Entry points

| What       | Where                                                                                                                                                                                      |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Command    | `posts:dispatch-due` (`app/Console/Commands/DispatchDuePosts.php`), scheduled in `routes/console.php`                                                                                      |
| Dispatcher | `app/Services/Publishing/PostDispatcher.php` (`dispatchDue`, `dispatchPost`, `isEnabled`)                                                                                                  |
| Job        | `app/Jobs/PublishPostTargetJob.php`                                                                                                                                                        |
| Status     | `app/Services/Publishing/PostStatusResolver.php`                                                                                                                                           |
| Contract   | `app/Services/Publishing/Publisher.php`, `PublishResult.php`, `PublishException.php`, `PublisherRegistry.php` (bound in `AppServiceProvider` with `LinkedInPublisher`, `YouTubePublisher`) |
| Routes     | `POST /posts` and `PUT /posts/{post}` with `intent=publish_now`; `POST /posts/{post}/retry` (`posts.retry`)                                                                                |
| UI         | `resources/js/components/posts/DeliveryPanel.vue` (per account: status, tries, last error, try history, View post link), Publish now and Retry buttons in `posts/Form.vue`                 |

## Data

**`publish_attempts`**: `post_target_id`, `attempt`, `status` (`running`, `succeeded`, `failed`), `retryable`, `error_message`, `error_class`, `external_id`, `started_at`, `finished_at`, `duration_ms`.

`post_targets` gets filled in here: `status`, `attempts`, `last_error`, `external_id`, `external_url`, `published_at`.

`PublishResult` can carry a `notice` (for example a YouTube thumbnail that was refused). The job saves it in `last_error` on a **published** account, so the Delivery panel shows "Published, but …".

## Rules

- A post is locked by the `scheduled → publishing` update; the job also skips published accounts. Together these stop double posting.
- More than 6 hours late = missed, never sent automatically. Use Retry to send it anyway, or reschedule it.
- Only temporary problems are retried. A publisher must throw `PublishException::permanent()` for errors a retry can't fix (bad token, invalid content).
- **Never retry the call that makes a post public** after a dropped connection or a 5xx: the platform may already have posted it, so a retry posts it twice. Facebook (`FacebookPublisher::finalCall()`), LinkedIn (`LinkedInPublisher::createPost()`) and Instagram (`publishContainer()`) turn these into a permanent "may already be posted. Check … before you retry." Uploads before that call stay retryable.
- Media used by a failed or partly published post that still has failed accounts can't be deleted, so a retry can't silently post without it.
- Queue `retry_after` is set to 600 s (`DB_QUEUE_RETRY_AFTER`), longer than the job timeout of 540 s, so a long upload is never picked up twice.

## Adding a platform's publisher

1. Create a class implementing `Publisher` (`provider()`, `publish(PostTarget): PublishResult`).
2. Add it to the array in `AppServiceProvider::register()`.
3. Throw `PublishException::retryable()` for network, 5xx and 429 errors, and `permanent()` for the rest. Exception: on the final call that makes the post public, a dropped connection or 5xx is `permanent()` with "may already be posted".
4. Don't call `LogService::startFeatureLogging()` inside it; log on the job's trace.

Example: `app/Services/LinkedIn/LinkedInPublisher.php` (see `knowledge-base/linkedin/README.md` → Publishing).

## Logging

| Feature    | Message                                                        | Level   | Context                                                        |
| ---------- | -------------------------------------------------------------- | ------- | -------------------------------------------------------------- |
| scheduler  | `Publishing is turned off, due posts are left scheduled`       | info    |                                                                |
| scheduler  | `Due posts checked`                                            | info    | `dispatched`, `missed`, `timed_out`                            |
| scheduler  | `Post sent to the publish queue`                               | info    | `post_id`, `accounts`, `scheduled_at`                          |
| scheduler  | `Post missed its time and was not sent`                        | warning | `post_id`, `scheduled_at`                                      |
| scheduler  | `Publishing timed out for an account`                          | warning | `post_id`, `target_id` (pending or publishing, no queued job)  |
| publishing | `Retry refused`                                                | warning | `reasons`                                                      |
| publishing | `Final publish call failed, may already be posted`             | warning | `status` (null = dropped connection), exception                |
| publishing | `Publishing to account`                                        | info    | `account_id`, `attempt` (+ `post_id`, `target_id`, `provider`) |
| publishing | `Published to account`                                         | info    | `account_id`, `external_id`, `duration_ms`                     |
| publishing | `Publishing failed, will retry`                                | warning | `attempt`, `retry_in_seconds`, exception                       |
| publishing | `Publishing failed for good`                                   | error   | `attempt`, `retryable`, exception                              |
| publishing | `Publish job failed outside the normal flow`                   | error   | exception                                                      |
| publishing | `Post status resolved`                                         | info    | `status`, `published`, `accounts`                              |
| publishing | `Publish now requested` / `Retry of failed accounts requested` | info    | `accounts` on retry                                            |

## Config (`.env`)

- `POSTLOOM_PUBLISHING_ENABLED=true`: set `false` to pause all sending (due posts stay scheduled).
- `POSTLOOM_CATCH_UP_HOURS=6`, `POSTLOOM_STUCK_MINUTES=30`
- `DB_QUEUE_RETRY_AFTER=600`

## Tests

- `tests/Feature/Scheduler/DispatchDuePostsTest.php`: one job per account; overlapping runs don't double-dispatch; future posts and drafts untouched; under 6 h late sent; over 6 h late missed; a missed post that already reached an account ends partly published; stuck accounts time out; a retry still in the queue is not timed out; a lost pending account times out; a scheduled post's accounts are never timed out; nothing while turned off; runs every minute.
- `tests/Feature/Scheduler/QueuedPublishJobsTest.php`: reads target ids from the database queue; none for other drivers.
- `tests/Feature/Scheduler/PublishPostTargetJobTest.php`: success saves link and status; retry after 60 s then 300 s; success on a retry; failed for good after 3 tries; permanent error fails at once; reconnect-needed account fails while others publish (partly published); no publisher for a platform; post stays publishing until all accounts finish; no double publish.
- `tests/Feature/Scheduler/PublishNowAndRetryTest.php`: publish now queues at once; stays scheduled when turned off; retry sends only failed accounts; retry refused when a failed account needs reconnecting (post stays failed, nothing queued); only failed accounts are checked; unschedule, retry and save refused once the scheduler took the post; retry refused for other statuses; other owner 403; delivery details in the editor.
- `tests/Support/FakePublisher.php` stands in for a real platform.

## Known limits

- Posts only go out while `start.ps1` (scheduler + queue worker) is running.
- Only LinkedIn has a publisher; other platforms fail with "Posting to … is not available yet."
