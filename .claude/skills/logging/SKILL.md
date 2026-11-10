---
name: logging
description: Postloom logging — use when adding logs to a controller, job, command or listener, adding a LogFeature enum case, debugging a failed post or job from storage/logs/postloom-*.log, tracing one run by trace_id, or adding the Axiom channel later. Covers LogService (startFeatureLogging, info, warning, error with exception), the postloom and postloom_file channels, and what must never be logged.
---

# Logging

KB: `knowledge-base/logging/README.md`. Rules: `.claude/rules/logging.md`.

## Key files

- `app/Services/LogService.php`
- `app/Enums/LogFeature.php`
- `config/logging.php` (channels `postloom`, `postloom_file`)

## Adding logs

```php
LogService::startFeatureLogging(LogFeature::Publishing, ['target_id' => $target->id]);
LogService::info('Publishing to LinkedIn', ['account_id' => $account->id, 'attempt' => $attempt]);
LogService::error('LinkedIn publish failed', ['status_code' => $status], $exception);
```

## Debugging

1. Open `storage/logs/postloom-YYYY-MM-DD.log` (one JSON object per line).
2. Find the failing line by `message` or by an id in `context`.
3. Take its `extra.trace_id` and read every line with that id to see the whole run in order:
   `Select-String -Path storage/logs/postloom-*.log -Pattern "<trace_id>"`
4. For publishing problems also check the `publish_attempts` table (from piece 07).
