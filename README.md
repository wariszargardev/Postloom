# Postloom

Plan social media posts on a calendar and publish them on time. LinkedIn first, then YouTube, Facebook, Instagram, TikTok and WhatsApp (limited). Many accounts per platform.

## Stack
Laravel 13 · PHP 8.4 · Inertia + Vue 3 · Tailwind 4 · MySQL 8.4 · Pest

## Run locally
1. `composer install` and `npm install`
2. Copy `.env.example` to `.env`, set `DB_PASSWORD`, run `php artisan key:generate`
3. `php artisan migrate`
4. `./start.ps1` → open http://localhost:8000 · stop with `./stop.ps1`

## Docs
- Rules: `CLAUDE.md`, `.claude/rules/`
- Knowledge base: `knowledge-base/README.md`
- Pieces and branches: `docs/roadmap.md`

## Tests
`php artisan test --compact`
