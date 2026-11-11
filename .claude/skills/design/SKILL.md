---
name: design
description: Postloom design system — use when building or changing any page or component in resources/js (layout, colours, fonts, cards, badges, icons), adding a status or a platform to the UI, touching resources/css/app.css tokens, the logo, the dashboard counters, the calendar event colours, or dark/light mode. Also for "this page looks off", "text is hard to read", "dropdown unreadable in dark mode", "wrong status colour", or matching the Build Real Code content style (navy, green, amber, Inter + JetBrains Mono).
---

# Design

KB: `knowledge-base/design/README.md` (tokens, building blocks, page rules). Screenshots: `docs/design/screenshots/`.

## Key files

- `resources/css/app.css`: tokens (`:root`, `.dark`), status colours, `.page`, `.surface`, `.section-title`, `.step-number`, `.empty-state`, `.filter-chip`, `.field-input`, `.bg-grid`
- `resources/js/components/StatusBadge.vue`, `PlatformIcon.vue`, `AppLogo.vue`, `AppLogoIcon.vue`, `Heading.vue`, `NavMain.vue`
- `vite.config.ts` (fonts via `bunny()`), `app/Http/Middleware/HandleAppearance.php` (default dark)
- `app/Http/Controllers/DashboardController.php`, `resources/js/pages/Dashboard.vue`

## Checklist for a new or changed page

1. Root element `class="page"` (or `page-narrow` for forms and settings), with `Heading` at the top.
2. Cards are `.surface`; empty lists use `.empty-state`.
3. Statuses use `StatusBadge`; platforms use `PlatformIcon`.
4. Times, counts and handles use `font-mono`.
5. No raw hex colours; use the token classes (`text-brand`, `text-amber`, `text-status-*`).
6. Look at it in dark and light mode (browser pane `resize_window` with `colorScheme`).

## Debugging

| Symptom                     | Check                                                                                        |
| --------------------------- | -------------------------------------------------------------------------------------------- |
| Fonts look like Arial       | Vite was not restarted after `vite.config.ts` changed; `stop.ps1` + `start.ps1`              |
| Page is light on first load | `appearance` cookie set to light/system; Settings → Appearance                               |
| A status shows grey         | Missing tone in `StatusBadge.vue` or `--status-*` token                                      |
| Calendar event uncoloured   | `PostStatus::calendarColor()` value has no `.postloom-event--*` rule in `calendar/Index.vue` |
