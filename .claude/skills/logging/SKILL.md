---
name: logging
description: Postloom logging and Axiom — use when adding logs to a controller, job, command or listener, adding a LogFeature enum case, debugging a failed post or job from storage/logs/postloom-*.log or in Axiom, tracing one run by trace_id, turning Axiom on or off for an environment (POSTLOOM_LOG_CHANNELS, AXIOM_TOKEN, AXIOM_DATASET), or when lines don't reach Axiom ("Axiom refused", "could not send … to Axiom", HTTP 401/403/404). Covers LogService (startFeatureLogging, info, warning, error with exception), the postloom, postloom_file and postloom_axiom channels, AxiomHandler batching and redaction, FlushAxiomLogs, and what must never be logged.
---

# Logging

KB: `knowledge-base/logging/README.md`. Rules: `.claude/rules/logging.md`. Axiom setup: `docs/guides/axiom/README.md`.

## Key files

- `app/Services/LogService.php`, `app/Enums/LogFeature.php`
- `config/logging.php` (channels `postloom`, `postloom_file`, `postloom_axiom`, `postloom_testing`)
- `app/Logging/AxiomHandler.php` (batching, redaction, send), `app/Logging/FlushAxiomLogs.php` (wired in `AppServiceProvider::flushAxiomLogs()`)

## Adding logs

```php
LogService::startFeatureLogging(LogFeature::Publishing, ['target_id' => $target->id]);
LogService::info('Publishing to LinkedIn', ['account_id' => $account->id, 'attempt' => $attempt]);
LogService::error('LinkedIn publish failed', ['status_code' => $status], $exception);
```

## Debugging in Axiom

Dataset per environment: `postloom-local`, `postloom-test`, `postloom-uat`, `postloom-prod`. Fields: `_time`, `level`, `message`, `context.*`, `extra.feature`, `extra.trace_id`, `extra.<ids>`, `environment`.

```kusto
// errors and warnings, last 24 h
['postloom-local'] | where _time > ago(24h) and level in ('error', 'warning') | sort by _time desc

// one feature (posts, publishing, scheduler, tiktok, instagram, …)
['postloom-local'] | where ['extra.feature'] == 'publishing' | sort by _time desc | take 100

// the whole run, in order
['postloom-local'] | where ['extra.trace_id'] == '<trace_id>' | sort by _time asc

// everything about one post
['postloom-local'] | where ['extra.post_id'] == 24 or ['context.post_id'] == 24 | sort by _time asc

// failures per feature
['postloom-local'] | where level == 'error' | summarize count() by ['extra.feature'], message
```

## Debugging from the file

1. Open `storage/logs/postloom-YYYY-MM-DD.log` (one JSON object per line).
2. Find the failing line by `message` or by an id in `context`.
3. Take its `extra.trace_id` and read every line with that id:
   `Select-String -Path storage/logs/postloom-*.log -Pattern "<trace_id>"`
4. For publishing problems also check the `publish_attempts` table.

## Axiom not receiving lines

| Symptom                                              | Check                                                                                                                                        |
| ---------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Nothing in Axiom, no error anywhere                  | `POSTLOOM_LOG_CHANNELS` lacks `postloom_axiom`, or `AXIOM_TOKEN` / `AXIOM_DATASET` empty; `php artisan config:clear` and restart `start.ps1` |
| `Postloom: Axiom refused N log lines (HTTP 401/403)` | Token wrong or has no Ingest permission on that dataset                                                                                      |
| `… (HTTP 404)`                                       | Dataset name in `AXIOM_DATASET` doesn't exist, or `AXIOM_URL` is for another region                                                          |
| `could not send N log lines to Axiom: …`             | No internet / timeout (3 s). Those lines are dropped; the file log still has them                                                            |
| Worker lines show up late                            | Lines are sent after each job; a job that is still running hasn't flushed yet                                                                |

The `Postloom: …` messages go to PHP's error log (the `start.ps1` terminal tabs), never to Axiom itself.
