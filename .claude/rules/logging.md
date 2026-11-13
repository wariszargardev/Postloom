# Logging rules

Use `App\Services\LogService` only. Never call `Log::` directly in app code.

- Every entry point (controller action, job `handle`, command `handle`, listener) starts with (this clears Context left from an earlier run; helper services called inside a run must not call it again)
  `LogService::startFeatureLogging(LogFeature::<Feature>, ['<id>' => ...])`. Add an enum case to `App\Enums\LogFeature` if none fits.
- If an entry point calls a service that starts its own run (for example `PostService::save()`), continue your own run afterwards with `LogService::startFeatureLogging(LogFeature::<Feature>, [...], $traceId)` using the `trace_id` returned at the start.
- Tests log to `storage/logs/testing/` (`POSTLOOM_LOG_CHANNELS=postloom_testing` in `phpunit.xml`); never point tests at the real `postloom-*.log`.
- Log at: entry, key decisions (skipped, retried, chosen account), every external API call (before and result), failures, final result.
- `LogService::info('<clear message>', [<context>])`, `LogService::warning('<message>', [<context>], $exception)` and `LogService::error('<message>', [<context>], $exception)`. The exception is optional for warning and error and adds `exception_class`, `exception_message` and `exception_location`.
- Context holds ids and statuses (`post_id`, `target_id`, `account_id`, `provider`, `attempt`, `status_code`). Never tokens, secrets, passwords or full API responses.
- Output: JSON lines in `storage/logs/postloom-YYYY-MM-DD.log` (channel `postloom` → `postloom_file`). Every line has `feature` and `trace_id` from the context.
- Axiom later: add an `axiom` channel and set `POSTLOOM_LOG_CHANNELS=postloom_file,axiom` in `.env`. No app code changes.
- Business history of publishing is also stored in the database (`publish_attempts`), so the UI can show it without reading logs.
