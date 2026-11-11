# Design system

## Purpose

Postloom looks like the Build Real Code content it publishes: deep navy surfaces, green as the main colour, amber as the accent, Inter for text and JetBrains Mono for numbers, handles and times. Dark is the default theme; light mode uses the same roles with darker greens and ambers for contrast. Piece 12 (`feat/12-design-refresh`).

Before / after screenshots of every page: `docs/design/screenshots/`.

## Tokens (`resources/css/app.css`)

| Role                                      | Dark                                      | Light     | Tailwind                    |
| ----------------------------------------- | ----------------------------------------- | --------- | --------------------------- |
| Page background                           | `#0b111c` + faint 32 px grid (`.bg-grid`) | `#f5f7fb` | `bg-background`             |
| Cards / panels                            | `#111a2b`                                 | `#ffffff` | `bg-card`, `.surface`       |
| Text                                      | `#e5e9f2`                                 | `#0b111c` | `text-foreground`           |
| Secondary text                            | `#94a3b8`                                 | `#526077` | `text-muted-foreground`     |
| Main colour (buttons, links, active menu) | green `#3ddc84`                           | `#15924f` | `bg-primary`, `text-brand`  |
| Accent (step numbers, "scheduled")        | amber `#f5a83a`                           | `#c27510` | `text-amber`, `bg-amber/15` |
| Borders                                   | `#1f2b40`                                 | `#dbe3ee` | `border`                    |

**Status colours** (`--status-*`, Tailwind `text-status-*` / `bg-status-*`), used by `StatusBadge`, the calendar and the dashboard:

| Status                    | Colour            |
| ------------------------- | ----------------- |
| Draft / pending           | slate             |
| Scheduled / expiring soon | amber             |
| Publishing                | blue, pulsing dot |
| Published / connected     | green             |
| Partly published          | orange            |
| Failed / reconnect needed | red               |

**Fonts:** Inter (400–800) and JetBrains Mono (400, 500, 700), self-hosted by `laravel-vite-plugin/fonts` (`bunny()` in `vite.config.ts`, no npm packages). Base size is 106.25 % (17 px) for readability. Use `font-mono` for times, counts, handles and token info.

**Radius:** 0.75 rem (`rounded-lg`), `rounded-xl` for cards.

## Building blocks

| Class / component                 | Use                                                                    |
| --------------------------------- | ---------------------------------------------------------------------- |
| `.page` / `.page-narrow`          | Page body: centred, max 72 rem / 56 rem wide, padded                   |
| `.surface`                        | Any card or panel                                                      |
| `.section-title` + `.step-number` | Section headings; amber number badges in the post editor               |
| `.empty-state`                    | "Nothing here yet" boxes                                               |
| `.filter-chip` (`data-active`)    | Status filter pills (Posts)                                            |
| `.field-input`                    | Text inputs, textareas, selects outside shadcn `Input`                 |
| `StatusBadge.vue`                 | Every status pill (post, account on a post, social account)            |
| `PlatformIcon.vue`                | Platform mark (LinkedIn, YouTube, Facebook…) on chips, lists, calendar |
| `AppLogoIcon.vue` / `AppLogo.vue` | Green "P" on navy with an amber dot; wordmark "Post**loom**" in mono   |
| `Heading.vue`                     | Page title (extra-bold) and description                                |

Logo files: `docs/brand/postloom-logo-green.svg` / `.png`, `public/favicon.svg|ico`, `public/apple-touch-icon.png`. The old purple logo stays in `docs/brand/postloom-logo.png` for reference.

## Pages

- **Sidebar:** logo + tagline, workspace switcher, "Menu" items; the active item is green with a green left bar.
- **Header:** sticky, blurred background.
- **Dashboard** (`DashboardController`): 4 counters (scheduled in the next 7 days, published or partly published in the last 30 days, failed + partly published, accounts needing attention), each linking to the matching list, and "Up next" (next 5 scheduled or publishing posts, from an hour ago on).
- **Posts:** cards with the first media thumbnail, status pill, time in mono, platform chips.
- **Post editor:** form (max ~760 px) with numbered step cards, sticky action bar at the bottom, sticky preview and delivery panel on the right.
- **Calendar:** events tinted by status with a coloured left edge and platform icons; partly published has its own orange (`PostStatus::calendarColor()` → `partly`).
- **Accounts, Media, Workspaces, Login:** same surfaces, platform icons, mono details.

## Rules

- Use the tokens and building blocks above; never hard-code hex colours in pages (platform brand colours in `PlatformIcon` are the exception).
- Any new status must get a `--status-*` token and a `StatusBadge` tone.
- Any new platform must get a `PlatformIcon` mark.
- Check new pages in both dark and light mode.

## Logging

None (presentation only). The dashboard query is read-only.

## Config

- Default theme: `dark` (`HandleAppearance` middleware, `app.blade.php`, `useAppearance.ts`). Users can still pick Light or System in Settings → Appearance.

## Tests

- `tests/Feature/DashboardStatsTest.php`: counters for the current workspace only; Up next order and platforms.
- `tests/Feature/Posts/*`, `tests/Feature/Calendar/*` still pass with the new props (`thumbnail_url`, `provider`) and the `partly` calendar class.

## Known limits

- Screenshots in `docs/design/screenshots/` are 60 % scale captures at 1440×900 in dark mode.
