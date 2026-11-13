# Project setup

## Purpose

How Postloom runs locally: the stack, the database, the processes and the folder layout.

## Flow

`start.ps1` starts:

1. MySQL (shared portable server, port 3307) if nothing answers on 3307. It may already run as the `MySQL-BRC` Windows service; `start.ps1` checks by connecting to the port, because the shared `start-mysql.ps1` can't always see a service's port and would try a second server (`ibdata1 must be writable` in `mysqld.err`).
2. The web server on `http://localhost:8000` (the URL platform OAuth callbacks use): PHP's built-in server with `upload_max_filesize=512M` and `post_max_size=520M` for this project only (see `knowledge-base/media/README.md`).
3. `php artisan schedule:work`: runs scheduled commands every minute (the publisher check arrives in piece 07).
4. `php artisan queue:work`: runs queued jobs from the `jobs` table.
5. `npm run dev` (Vite) for the Vue pages.
6. `cloudflared tunnel --url http://localhost:8000` (only if `cloudflared` is installed): a free Cloudflare quick tunnel so Instagram can fetch photos. Its address is saved to `storage/app/postloom-run/tunnel-url.txt` (`stop.ps1` deletes it); only signed media links answer through it (`knowledge-base/instagram/README.md`).

With Windows Terminal (`wt.exe`) the processes open as **tabs in one window** ("Postloom web", "scheduler", "queue", "vite", "tunnel"); without it, each opens its own PowerShell window. Each process runs from a generated script in `storage/app/postloom-run/<name>.ps1` that writes its own process id to `storage/app/postloom-pids/<name>.pid` (running a file keeps Windows Terminal from treating `;` as "next tab").

`stop.ps1` stops each saved process with its child processes (`taskkill /T`), so the tabs or windows close, and deletes the pid files. It also understands the old single `postloom-pids.txt`. It does not stop MySQL because other projects share it; pass `-WithMySql` to stop it too.

If `start.ps1` says "already running" but nothing is open (for example after a reboot), run `stop.ps1` once; it clears the stale pid files.

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
