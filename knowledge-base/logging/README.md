# Logging

## Purpose

One way to log everything, so any run can be traced from start to finish and later sent to Axiom without changing code.

## Flow

1. An entry point calls `LogService::startFeatureLogging(LogFeature::X, [ids])`. This adds `feature`, a new `trace_id` and the ids to Laravel's `Context`.
2. Code calls `LogService::info`, `warning` or `error` (warning and error take an optional exception). Laravel adds the Context to every line.
3. The `postloom` stack channel writes to the channels listed in `POSTLOOM_LOG_CHANNELS` (default `postloom_file`).
4. `postloom_file` writes JSON lines to `storage/logs/postloom-YYYY-MM-DD.log`, kept for `POSTLOOM_LOG_DAYS` days.

## Entry points

- `app/Services/LogService.php`
- `app/Enums/LogFeature.php`: `system`, `auth`, `workspaces`, `accounts`, `linkedin`, `media`, `posts`, `calendar`, `scheduler`, `publishing`
- `config/logging.php`: channels `postloom`, `postloom_file`

## Data

Each line: `message`, `context` (what you passed, plus exception details on errors), `level_name`, `datetime`, `extra` (`feature`, `trace_id` and other Context values).

## Rules

See `.claude/rules/logging.md`. No tokens, secrets or full API responses in logs.

## Logging

This is the logging feature itself.

## Config (`.env`)

- `POSTLOOM_LOG_CHANNELS=postloom_file` (comma list; add `axiom` later)
- `POSTLOOM_LOG_DAYS=14`
- `LOG_LEVEL=debug`

## Tests

`tests/Unit/Services/LogServiceTest.php`: the context has feature and trace id, info goes to the `postloom` channel, error adds exception details, a real JSON line is written to the daily file.

## Known limits

- No Axiom channel yet. When added: create the channel in `config/logging.php`, add `AXIOM_*` keys to `.env`, and update this doc.
