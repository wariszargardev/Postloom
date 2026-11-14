# Axiom (log search)

Send Postloom's logs to [Axiom](https://axiom.co) so you can search them by feature, `trace_id`, post or account instead of opening `storage/logs/postloom-*.log`. The file log keeps working; Axiom is added next to it.

Same setup for every environment, only the dataset and token differ:

| Environment | `APP_ENV`    | `AXIOM_DATASET`  |
| ----------- | ------------ | ---------------- |
| Your laptop | `local`      | `postloom-local` |
| Test        | `test`       | `postloom-test`  |
| UAT         | `uat`        | `postloom-uat`   |
| Production  | `production` | `postloom-prod`  |

Every line also carries `environment` (from `APP_ENV`), so one shared dataset works too if you prefer.

## 1. Create the dataset

1. Sign in at <https://app.axiom.co> (the free plan is enough).
2. **Settings → Datasets → New dataset**.
3. Name: `postloom-local` (see the table above). Retention: the default is fine.

## 2. Create an ingest-only API token

1. **Settings → API tokens → New API token**.
2. Name: `postloom-local ingest`.
3. Permissions: only **Ingest** on the `postloom-local` dataset. No query, no other datasets. If this token leaks, nobody can read your logs with it.
4. Copy the token (it starts with `xaat-`). Axiom shows it once.

## 3. Turn it on in `.env`

Put the token in `.env` yourself; never paste it in chat, a commit or a screenshot.

```dotenv
POSTLOOM_LOG_CHANNELS=postloom_file,postloom_axiom
AXIOM_TOKEN=xaat-...
AXIOM_DATASET=postloom-local
AXIOM_URL=https://api.axiom.co
AXIOM_BATCH_SIZE=50
```

Then:

```powershell
php artisan config:clear
./stop.ps1
./start.ps1
```

The queue worker and scheduler keep old settings in memory until they restart.

`AXIOM_URL`: keep `https://api.axiom.co` unless your Axiom organisation is in another region; then use the API URL Axiom shows under **Settings → Organization**.

## 4. Check it works

1. Open Postloom and do anything that logs (save a draft, open Accounts).
2. In Axiom open **Query**, pick the dataset and run:

```kusto
['postloom-local']
| sort by _time desc
| take 20
```

Lines appear within a few seconds (they are sent at the end of each request, job or command).

Nothing there? Check `storage/logs/laravel.log` and the terminal of `start.ps1` for `Postloom: Axiom refused …` (wrong token: HTTP 401/403; wrong dataset name: 404) or `could not send … to Axiom` (no internet). Postloom keeps running either way.

## 5. Turning it off

Set `POSTLOOM_LOG_CHANNELS=postloom_file`, then `php artisan config:clear` and restart. To stop it for good, delete the token in Axiom.

Useful queries are in `.claude/skills/logging/SKILL.md`.
