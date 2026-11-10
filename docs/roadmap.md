# Roadmap

Each piece = one branch from `develop` = one PR into `develop`. Status: `planned`, `in progress`, `in review`, `merged`.

| #   | Branch                        | Title                                    | Description                                                                                                                  | Status    |
| --- | ----------------------------- | ---------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | --------- |
| 00  | `chore/00-project-setup`      | Project setup, rules, KB and skills base | Laravel 13 Vue app on MySQL, project rules, KB, base skills, LogService, start/stop scripts, branch flow                     | merged    |
| 01  | `feat/01-auth`                | Email login                              | Single-owner registration, email verification off, auth event logging, starter kit links removed                             | in review |
| 02  | `feat/02-workspaces-accounts` | Workspaces and social accounts           | Workspaces to group accounts; `social_accounts` with many accounts per platform, encrypted tokens, expiry and reconnect flag | planned   |
| 03  | `feat/03-linkedin-connect`    | Connect a LinkedIn profile               | OAuth (OpenID Connect + `w_member_social`), store person URN and token, expiry warning                                       | planned   |
| 04  | `feat/04-media-library`       | Media library                            | Upload and reuse images, videos, PDFs and audio; type and size checks                                                        | planned   |
| 05  | `feat/05-post-editor`         | Post editor                              | Main text, per-account override and settings, media, preview, drafts                                                         | planned   |
| 06  | `feat/06-calendar`            | Calendar                                 | Month/week/day views, click a slot to create, drag to reschedule, failed posts in red                                        | planned   |
| 07  | `feat/07-scheduler`           | Scheduler and publish queue              | Every-minute due check, one job per target, lock, retries, `publish_attempts`, catch-up for missed posts                     | planned   |
| 08  | `feat/08-linkedin-publish`    | Publish to LinkedIn                      | Text, multi-image, video and document posts to the personal profile; audio converted to video                                | planned   |

## Later

| Branch                    | Title                                                               |
| ------------------------- | ------------------------------------------------------------------- |
| `feat/linkedin-pages`     | LinkedIn company pages + guide `docs/guides/linkedin-page-setup.md` |
| `feat/csv-import`         | Import posts from CSV                                               |
| `feat/youtube`            | YouTube channels                                                    |
| `feat/facebook-instagram` | Facebook pages and Instagram                                        |
| `feat/tiktok`             | TikTok                                                              |
| `feat/axiom-logging`      | Send logs to Axiom                                                  |
