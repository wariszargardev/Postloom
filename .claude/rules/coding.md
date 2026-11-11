# Coding rules

- Flow: route → controller (thin) → service (business logic) → Inertia page. Form Requests for validation.
- One platform = one driver class behind a shared contract (`connect`, `refreshToken`, `validate`, `publish`). The calendar, editor and scheduler never know platform details.
- Clear, self-explaining names (`scheduledAt`, `isTokenExpired`). No code comments unless asked; PHPDoc for array shapes only.
- PHP: constructor property promotion, typed params and return types, curly braces always, enums for fixed values (`app/Enums`).
- Secrets (tokens, client secrets) only in `.env` or encrypted model casts. Never in code, logs or Git.
- Platform API calls go through Laravel's `Http` client so tests can `Http::fake()` them. Tests never call real APIs.
- Tests: Pest, factories for data, no `DB::` in tests, resolve a service once per file in `beforeEach`.
- Run `vendor/bin/pint --dirty` after PHP changes.
- New dependency (Composer or npm) only after Waris agrees.
- UI: follow `knowledge-base/design/README.md` (the `design` skill). Pages use `.page`/`.page-narrow`, cards `.surface`, statuses `StatusBadge`, platforms `PlatformIcon`, colours from tokens only (no raw hex), and must look right in dark and light mode.
