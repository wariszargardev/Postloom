# Project setup

## Purpose

How Postloom runs locally: the stack, the database, the processes and the folder layout.

## Flow

`start.ps1` starts:

1. MySQL (shared portable server, port 3307) if it is not already running.
2. The web server on `http://localhost:8000` (the URL platform OAuth callbacks use): PHP's built-in server with `upload_max_filesize=512M` and `post_max_size=520M` for this project only (see `knowledge-base/media/README.md`).
3. `php artisan schedule:work`: runs scheduled commands every minute (the publisher check arrives in piece 07).
4. `php artisan queue:work`: runs queued jobs from the `jobs` table.
5. `npm run dev` (Vite) for the Vue pages.

Each process opens in its own PowerShell window so its output is visible. `stop.ps1` stops the PHP and Node processes started from this folder. It does not stop MySQL because other projects share it; pass `-WithMySql` to stop it too.

## Entry points

- Web: `routes/web.php`, `routes/settings.php` (starter kit pages: welcome, login, register, dashboard, profile, security).
- Auth: Laravel Fortify (`app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify`). Email + password, optional two-factor and passkeys.

## Data

- Database `postloom` on `127.0.0.1:3307`, user `postloom` (password only in `.env`).
- Starter tables: `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `passkeys`.
- Tests use in-memory SQLite (`phpunit.xml`), so they never touch the real database. An empty `postloom_testing` MySQL database also exists if MySQL-based tests are ever needed.

## Rules

- App login is email/password. Social accounts (LinkedIn, YouTube…) are connected later only for publishing, not for logging in.
- Folder layout: `app/Services` (business logic), `app/Enums`, `app/Http` (controllers, requests), `resources/js/pages` (Inertia pages), `knowledge-base/`, `.claude/rules/`, `.claude/skills/`, `docs/`.

## Logging

See [logging](../logging/README.md).

## Config (`.env`)

`APP_NAME=Postloom`, `APP_URL=http://localhost:8000`, `DB_*` (port 3307), `QUEUE_CONNECTION=database`, `POSTLOOM_LOG_CHANNELS`, `POSTLOOM_LOG_DAYS`, `POSTLOOM_TIMEZONE` (timezone for scheduled commands), `LINKEDIN_*` (see `knowledge-base/linkedin/README.md`), `POSTLOOM_MEDIA_DISK`, `FFMPEG_PATH`, `FFPROBE_PATH` (see `knowledge-base/media/README.md`).

## Tests

`php artisan test --compact` runs the starter kit auth and settings tests plus `tests/Unit/Services/LogServiceTest.php`.

## Known limits

- Posts only go out while the PC is on and `start.ps1` is running. Catch-up rules for missed posts come with the scheduler (piece 07).
