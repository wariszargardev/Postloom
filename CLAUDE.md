# Postloom

Personal social media scheduler: plan posts on a calendar and publish them on time to LinkedIn first, then YouTube, Facebook, Instagram, TikTok and WhatsApp (limited). Many accounts per platform are allowed.

## Stack

- PHP 8.4, Laravel 13, Fortify (email login), Inertia v3 + Vue 3 + Tailwind 4 (Laravel Vue starter kit), Wayfinder
- MySQL 8.4 on `127.0.0.1:3307` (portable server in `D:\Afia Insurance\Projects\Personal\.mysql-brc`), database `postloom`
- Database queue, `php artisan schedule:work` + `php artisan queue:work`
- Pest for tests (in-memory SQLite), Pint for formatting

## Start here

1. `knowledge-base/README.md`: what exists and how it works.
2. `docs/roadmap.md`: the pieces, their branches and status.
3. `.claude/skills/postloom/SKILL.md`: which feature skill to open.

## Rules (details in `.claude/rules/`)

- Work in small pieces. One piece = one branch = one PR into `develop` (`git-workflow.md`).
- Plan first and wait for "approved" before writing code for a piece.
- Every piece ships with a KB doc, a skill, rule updates if needed, a roadmap update, logging and tests (`kb-and-skills.md`).
- Code style and structure: `coding.md`. Logging: `logging.md`.
- When a piece is done and tested, commit, push and open the PR into `develop` directly. Never merge; Waris merges.

## Commands

- Start everything: `./start.ps1` (MySQL, web server, scheduler, queue, Vite). Stop: `./stop.ps1`.
- Tests: `php artisan test --compact <file or --filter=name>`
- Full CI check (run before every PR, same as GitHub Actions): `npm run build` then `composer ci:check` (a stale `public/build` makes page tests fail with "Not a valid Inertia response")
- Format: `vendor/bin/pint --dirty`
- Frontend build: `npm run build`
