# Logging

## Purpose

One way to log everything, so any run can be traced from start to finish, in the daily file and (piece 21) in Axiom.

## Flow

1. An entry point calls `LogService::startFeatureLogging(LogFeature::X, [ids])`. This first clears any Context left from an earlier run (queued jobs receive the dispatcher's Context), then adds `feature`, a new `trace_id` and the ids to Laravel's `Context`. Helpers called inside a run (for example `PostStatusResolver`) must not call it again, so the whole run keeps one `trace_id`. When an entry point has to call a service that starts its own run (the CSV import calls `PostService::save()` per row), it continues its own run afterwards with `startFeatureLogging(LogFeature::X, [ids], $traceId)`, passing the `trace_id` it got at the start.
2. Code calls `LogService::info`, `warning` or `error` (warning and error take an optional exception). Laravel adds the Context to every line.
3. The `postloom` stack channel writes to the channels listed in `POSTLOOM_LOG_CHANNELS` (default `postloom_file`).
4. `postloom_file` writes JSON lines to `storage/logs/postloom-YYYY-MM-DD.log`, kept for `POSTLOOM_LOG_DAYS` days.
5. `postloom_axiom` (when listed) keeps lines in memory in `AxiomHandler` and sends them to Axiom in one HTTP call:
    - when `AXIOM_BATCH_SIZE` lines are waiting (default 50);
    - at the end of every web request (`app()->terminating`);
    - after every queued job (`JobProcessed`, `JobFailed`, `JobExceptionOccurred`), console command (`CommandFinished`) and scheduled task (`ScheduledTaskFinished`, `ScheduledTaskFailed`), so the long-running queue worker doesn't hold lines back;
    - when PHP shuts down (and on `close()`).
      `FlushAxiomLogs` finds the `AxiomHandler` in the `postloom` channel and calls `flush()`.
6. **Tests** never write to the real file or Axiom: `phpunit.xml` sets `POSTLOOM_LOG_CHANNELS=postloom_testing`, which writes the same JSON lines to `storage/logs/testing/postloom-YYYY-MM-DD.log` (kept 3 days).

## Entry points

- `app/Services/LogService.php`
- `app/Enums/LogFeature.php`: `system`, `auth`, `workspaces`, `accounts`, `linkedin`, `youtube`, `facebook`, `instagram`, `tiktok`, `media`, `posts`, `calendar`, `scheduler`, `publishing`, `import`
- `config/logging.php`: channels `postloom`, `postloom_file`, `postloom_axiom`, `postloom_testing` (tests only)
- `app/Logging/AxiomHandler.php`, `app/Logging/FlushAxiomLogs.php`, `AppServiceProvider::flushAxiomLogs()`
- Setup guide: `docs/guides/axiom/README.md`

## Data

File line: `message`, `context` (what you passed, plus exception details on errors), `level_name`, `datetime`, `extra` (`feature`, `trace_id` and other Context values).

Axiom event (one per line, sent as a JSON array to `POST {AXIOM_URL}/v1/datasets/{AXIOM_DATASET}/ingest` with `Authorization: Bearer {AXIOM_TOKEN}`):

| Field                  | Value                                                                 |
| ---------------------- | --------------------------------------------------------------------- |
| `_time`                | log time, ISO 8601 with microseconds and offset (Axiom's time column) |
| `level` / `level_name` | `info` / `INFO`                                                       |
| `message`              | the message                                                           |
| `context`              | what you passed (redacted)                                            |
| `extra`                | `feature`, `trace_id`, ids from Context (redacted)                    |
| `channel`              | `postloom`                                                            |
| `environment`          | `APP_ENV`                                                             |
| `service`              | `postloom`                                                            |

## Rules

See `.claude/rules/logging.md`. No tokens, secrets or full API responses in logs.

- **Redaction:** before sending, any key in `context` or `extra` (at any depth) matching token, secret, password, authorization, cookie, api key, client key or code verifier becomes `[redacted]`. This is a safety net, not permission to log secrets.
- **Never breaks the app:** each send waits at most 3 seconds and never throws. A refused or failed batch is dropped and noted with `error_log` (`Postloom: Axiom refused N log lines (HTTP …)` / `Postloom: could not send N log lines to Axiom: …`), never through `Log::`, so it can't loop. The file log still has every line.
- **Missing token or dataset:** the handler stays silent and sends nothing.
- **Environments:** local, test, UAT and production use the same code; only `.env` differs (`AXIOM_DATASET` = `postloom-local`, `postloom-test`, `postloom-uat`, `postloom-prod`, each with its own ingest-only token).

## Logging

This is the logging feature itself. Axiom send problems go to PHP's error log only.

## Config (`.env`)

- `POSTLOOM_LOG_CHANNELS=postloom_file` (comma list; `postloom_file,postloom_axiom` turns Axiom on)
- `POSTLOOM_LOG_DAYS=14`
- `LOG_LEVEL=debug` (also the Axiom level)
- `AXIOM_TOKEN` (ingest-only API token, `xaat-…`; you put it in `.env` yourself)
- `AXIOM_DATASET` (`postloom-local` etc.)
- `AXIOM_URL=https://api.axiom.co`
- `AXIOM_BATCH_SIZE=50`

After changing them: `php artisan config:clear`, then restart `start.ps1` (queue worker and scheduler keep old config in memory).

## Tests

- `tests/Unit/Services/LogServiceTest.php`: the context has feature and trace id, info goes to the `postloom` channel, error adds exception details, tests use the `postloom_testing` channel and a real JSON line is written to the daily test file (never the real `postloom-*.log`).
- `tests/Unit/Logging/AxiomHandlerTest.php`: lines wait until the batch is full and go out in one request with the bearer token and ingest URL; event shape (`_time`, level, message, context, extra, environment, service); secrets redacted at any depth; nothing sent without token or dataset; 503, 401 and connection errors don't throw and drop the batch; `LogService` lines are sent when a command finishes (`FlushAxiomLogs`).

## Known limits

- A failed batch is dropped (no retry queue). The daily file keeps the full history.
- Lines from a process killed with `kill -9` before a flush are lost from Axiom (still in the file).
- Laravel's own framework errors go to the default `stack` channel (`laravel.log`), not to Axiom.
