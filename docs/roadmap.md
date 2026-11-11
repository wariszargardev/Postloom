# Roadmap

Each piece = one branch from `develop` = one PR into `develop`. Status: `planned`, `in progress`, `in review`, `merged`.

| #   | Branch                          | Title                                    | Description                                                                                                                    | Status    |
| --- | ------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | --------- |
| 00  | `chore/00-project-setup`        | Project setup, rules, KB and skills base | Laravel 13 Vue app on MySQL, project rules, KB, base skills, LogService, start/stop scripts, branch flow                       | merged    |
| 01  | `feat/01-auth`                  | Email login                              | Single-owner registration, email verification off, auth event logging, starter kit links removed                               | merged    |
| 02  | `feat/02-workspaces-accounts`   | Workspaces and social accounts           | Workspaces to group accounts; `social_accounts` with many accounts per platform, encrypted tokens, expiry and reconnect flag   | merged    |
| 03  | `feat/03-linkedin-connect`      | Connect a LinkedIn profile               | OAuth (OpenID Connect + `w_member_social`), store person URN and token, expiry warning                                         | merged    |
| 04  | `feat/04-media-library`         | Media library                            | Upload and reuse images, videos, PDFs and audio; type and size checks                                                          | merged    |
| 05  | `feat/05-post-editor`           | Post editor                              | Main text, per-account override and settings, media, preview, drafts                                                           | merged    |
| 06  | `feat/06-calendar`              | Calendar                                 | Month/week/day views, click a slot to create, drag to reschedule, failed posts in red                                          | merged    |
| 07  | `feat/07-scheduler`             | Scheduler and publish queue              | Every-minute due check, one job per target, lock, retries, `publish_attempts`, catch-up for missed posts                       | merged    |
| 08  | `feat/08-linkedin-publish`      | Publish to LinkedIn                      | Text, multi-image, video and document posts to the personal profile; audio converted to video                                  | merged    |
| 09  | `docs/09-platform-setup-guides` | Platform setup guides                    | Step-by-step LinkedIn app guide with screenshots and video; template and video builder for future platforms                    | merged    |
| 10  | `feat/10-youtube-connect`       | Connect a YouTube channel                | Google OAuth with offline access, one account per channel, automatic token renewal, setup guide                                | merged    |
| 11  | `feat/11-youtube-publish`       | Publish to YouTube                       | Resumable video upload, title/privacy/made-for-kids settings, audio as video, quota-aware errors                               | merged    |
| 12  | `feat/12-design-refresh`        | Design refresh                           | Build Real Code look: navy, green and amber tokens, Inter + JetBrains Mono, status colours, platform icons, dashboard counters | in review |
| 13  | `feat/13-facebook-connect`      | Connect a Facebook Page                  | Meta app, Pages as accounts, setup guide (plan first)                                                                          | planned   |

## Later

| Branch                | Title                                                        |
| --------------------- | ------------------------------------------------------------ |
| `feat/linkedin-pages` | LinkedIn company pages + guide `docs/guides/linkedin-pages/` |
| `feat/csv-import`     | Import posts from CSV                                        |
| `feat/instagram`      | Instagram (needs a public media link)                        |
| `feat/tiktok`         | TikTok                                                       |
| `feat/axiom-logging`  | Send logs to Axiom                                           |
