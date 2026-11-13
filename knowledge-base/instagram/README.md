# Instagram

## Purpose

Connect one or more **Instagram professional accounts** (Business or Creator) to a workspace and publish photos, carousels, Reels and Stories to them automatically (piece 19). Each Instagram account is its own Postloom account.

Setup guide: `docs/guides/instagram/README.md`. It reuses the Meta app from `knowledge-base/facebook/README.md`.

### Why "Instagram API with Facebook Login"

Meta offers two logins for publishing (decided 2026-11-13):

| Login                                                           | Token / host                           | Needs a Page | Video upload from this PC                               |
| --------------------------------------------------------------- | -------------------------------------- | ------------ | ------------------------------------------------------- |
| **Facebook Login** (used)                                       | Page token, `graph.facebook.com`       | yes          | yes: resumable upload via `rupload.facebook.com`        |
| Instagram Login (`instagram_business_*`, `graph.instagram.com`) | Instagram user token, 60 days, refresh | no           | not documented, so videos would also need a public link |

Facebook Login reuses the existing Meta app, `FacebookGraphClient`, Page tokens that don't expire, and the daily Page token check. Meta's docs say resumable upload is "available only for apps that have implemented Facebook Login for Business".

Sources (Meta docs, read 2026-11-13): `developers.facebook.com/docs/instagram-platform/content-publishing`, `…/instagram-api-with-facebook-login`, `…/reference/ig-user/media`, `…/reference/ig-user/media_publish`, `…/reference/ig-container`, `…/reference/ig-user/content_publishing_limit`, `…/overview` (access levels). Full notes: the piece 19 research note.

### The public link

Meta: media "must be hosted on a publicly accessible server at the time of the attempt". Photos (`image_url`) have no upload option. Postloom runs on localhost, so:

- **Videos** (Reels, Story videos, carousel videos) are uploaded from disk with `upload_type=resumable`, with no public link.
- **Photos** get a signed, 15-minute link through a free **Cloudflare quick tunnel** (`cloudflared tunnel --url http://localhost:8000`, started by `start.ps1`).
- If no tunnel answers when a photo post is due, the account becomes **Ready for you** (`PostTargetStatus::ReadyToPost`) and the post waits under **To post** (manual hand-off from piece 17).

## Flow

### Connect

1. **Accounts → Instagram → Connect** opens `GET /accounts/instagram/redirect`. The workspace id goes into the session (`instagram_connect_workspace_id`). Socialite's `facebook` driver (same App ID, redirect `INSTAGRAM_REDIRECT_URI`) asks for `instagram_basic`, `instagram_content_publish`, `pages_show_list`, `pages_read_engagement`.
2. `GET /accounts/instagram/callback`: the state is checked, the code is swapped, then `FacebookGraphClient`:
    - `GET /oauth/access_token?grant_type=fb_exchange_token…` → long-lived user token.
    - `GET /me/permissions` → refused without `instagram_content_publish`.
    - `GET /me/accounts?fields=id,name,access_token,instagram_business_account{id,username,name,profile_picture_url}&limit=100`.
3. Every Page with an `instagram_business_account` gives one account (duplicates by Instagram id are dropped): provider `instagram`, type `profile`, `provider_account_id` = Instagram user id, `name` = Instagram name (or username), `handle` = username, avatar = profile picture, `access_token` = **that Page's token** (encrypted), no refresh token or expiry.
4. None found → "No Instagram professional account was found…". Connecting again updates the same accounts.
5. The daily `accounts:check-tokens` tests Instagram accounts with the Facebook Page check (`GET /me` with the Page token; 190 → `needs_reconnect`).

### Publish (`InstagramPublisher`)

1. Validate with `InstagramPostRules` (`mediaErrors()` + `targetErrors()`); any error is permanent.
2. If photos are involved (`needsPublicLink()`) and `PublicMediaUrl::isReachable()` is false (no address, or `GET {tunnel}/up` fails) → `PublishResult(needsManualPost: true)` with a notice; no Instagram call.
3. `GET /{ig-user}/content_publishing_limit?fields=quota_usage,config`: used up → permanent "limit of N API posts in 24 hours". Errors here only log a warning (190 still marks reconnect).
4. Containers (`POST /{ig-user}/media`, Page token):

| Format         | Calls                                                                                                                                                                                                                                             |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Post, 1 photo  | `image_url` (signed link), `caption`, `alt_text` (from the media library)                                                                                                                                                                         |
| Post, carousel | each item: photo `image_url` + `is_carousel_item=true`, or video `media_type=VIDEO` + `is_carousel_item=true` (resumable); wait for every child; then `media_type=CAROUSEL`, `children=c1,c2,…`, `caption`                                        |
| Reel           | `media_type=REELS`, `upload_type=resumable`, `caption`, `share_to_feed`; then the file to the returned `uri` (or `rupload.facebook.com/ig-api-upload/{v}/{container}`) with `Authorization: OAuth <token>`, `offset: 0`, `file_size`, binary body |
| Story          | photo: `media_type=STORIES` + `image_url`; video: `media_type=STORIES` resumable. Stories have no caption                                                                                                                                         |

5. `GET /{container}?fields=status_code,status` every `INSTAGRAM_STATUS_POLL_SECONDS` (15) until `FINISHED`, within `INSTAGRAM_STATUS_WAIT_SECONDS` (300) for the whole post. `ERROR` / `EXPIRED` → permanent with Instagram's `status` text. Still `IN_PROGRESS` at the deadline → retryable (nothing published yet, so a retry is safe).
6. `POST /{ig-user}/media_publish` `creation_id`. Error 9007 (not ready) → wait and try again (3 tries). Subcode 2207042 → permanent daily-limit message. A dropped connection here → **permanent** "may already be posted" (a retry could post twice).
7. `GET /{media}?fields=permalink` → saved as the post link (fallback `https://www.instagram.com/<handle>/`).
8. Audio files are turned into MP4 (`AudioToVideoConverter`) and the temp file deleted.

`INSTAGRAM_VIDEO_UPLOAD=url` switches videos to `video_url` via the tunnel too (for when Meta refuses resumable uploads for the app).

## Entry points

| Route                              | Name                          | Action                                                |
| ---------------------------------- | ----------------------------- | ----------------------------------------------------- |
| `GET /accounts/instagram/redirect` | `accounts.instagram.redirect` | `InstagramConnectController@redirect`                 |
| `GET /accounts/instagram/callback` | `accounts.instagram.callback` | `InstagramConnectController@callback`                 |
| `GET /public-media/{media}`        | `public-media.show`           | `PublicMediaController` (no login, `signed:relative`) |

- `app/Services/Instagram/InstagramConnectService.php`: `isConfigured()`, `redirect()`, `completeConnection()`; `SCOPES`, `PUBLISH_SCOPE`.
- `app/Services/Instagram/InstagramPublisher.php`: the `Publisher` for Instagram.
- `app/Services/Instagram/PublicMediaUrl.php`: `baseUrl()` (`INSTAGRAM_PUBLIC_BASE_URL` or the tunnel file), `isReachable()`, `for(Media)`.
- `app/Services/Posts/Rules/InstagramPostRules.php`: editor rules, `needsPublicLink()`.
- `app/Services/Media/JpegConverter.php`: PNG → JPEG (white background) when Instagram fetches a photo.
- `app/Http/Middleware/RestrictTunnelRequests.php`: global; requests through the tunnel (Cloudflare `CF-Ray` / `CF-Connecting-IP` header, or a non-local host) only reach `public-media/*` and `up`; everything else is a plain 404.
- `app/Exceptions/InstagramConnectException.php`.
- `start.ps1`: **tunnel** tab, writes `storage/app/postloom-run/tunnel-url.txt`; `stop.ps1` deletes it.
- Vue: Instagram cards in `components/posts/PostPreview.vue` (square feed card with carousel dots and "more" after 125 characters; vertical card for Reels and Stories), icon in `PlatformIcon.vue`, To post page `pages/to-post/Index.vue`.

## Data

`social_accounts` rows with `provider = instagram`, `account_type = profile`, the Page token in `access_token`. Post settings (`post_targets.settings`): `format` (`post` / `reel` / `story`), `share_to_feed` (Reels, default true).

## Rules

- `InstagramPostRules` (also in `knowledge-base/posts/README.md`): caption up to 2,200 characters, 30 hashtags, 20 @mentions; no text-only posts, no PDFs; photos JPG or PNG up to 8 MB; up to 10 items.
    - **Post**: 1 photo (a single video must use Reel) or a carousel of 2–10 photos/videos; feed photos 4:5 to 1.91:1.
    - **Reel**: exactly 1 video or audio file, 3 s to 15 min, up to 300 MB, MP4 or MOV.
    - **Story**: 1 photo (any ratio) or video of 3–60 s, up to 100 MB.
- Development mode is enough: Standard Access covers app users with a role on the app and their own accounts. Other people's accounts would need Advanced Access (App Review + business verification).
- The tunnel only exposes signed media links; links expire after `INSTAGRAM_PUBLIC_LINK_MINUTES` (15). Never widen `RestrictTunnelRequests::TUNNEL_PATHS`.
- Graph errors use `FacebookGraphClient`'s mapping (190 reconnect, rate limits retryable, 10/200 permission, 368 blocked).

## Logging

Feature `instagram` for connect; publishing runs on the `publishing` trace; the public media route starts its own `publishing` run with `media_id`.

| Message                                                                                      | Level          | Context                                                                |
| -------------------------------------------------------------------------------------------- | -------------- | ---------------------------------------------------------------------- |
| `Redirecting to Facebook to connect Instagram accounts`                                      | info           | `workspace_id`, `scopes`                                               |
| `Instagram callback received` / `Instagram connection cancelled`                             | info           | `workspace_id` / `error`                                               |
| `Facebook did not grant the Instagram publishing permission`                                 | warning        | `granted`                                                              |
| `Facebook returned Pages for Instagram`                                                      | info           | `page_count`, `pages` (`page_id`, `name`, `instagram_id` or null)      |
| `No Instagram professional account is linked to the shared Pages`                            | warning        |                                                                        |
| `Instagram accounts connected`                                                               | info           | `account_ids`, `instagram_ids`, `page_ids`                             |
| `Instagram callback state did not match` / `Instagram connection failed`                     | error          | exception                                                              |
| `Sending post to Instagram`                                                                  | info           | `instagram_id`, `format`, `media_count`, `media_kinds`, `video_upload` |
| `No public address for Instagram media is set` / `…could not be reached` / `…did not answer` | warning        | `base_url`, `status_code`                                              |
| `Instagram post handed off to you because the public link is not running`                    | warning        | `format`                                                               |
| `Instagram publishing limit read` / `…could not be read, posting anyway`                     | info / warning | `quota_usage`, `quota_total`                                           |
| `Instagram carousel item created`                                                            | info           | `position`, `kind`                                                     |
| `Instagram video uploaded`                                                                   | info           | `container_id`, `media_type`, `size_bytes`                             |
| `Instagram container ready`                                                                  | info           | `container_id`, `polls`                                                |
| `Instagram could not process the media` / `Instagram is still processing the media`          | warning        | `container_id`, `status_code`, `status` / `polls`                      |
| `Instagram media not ready to publish yet, waiting`                                          | info           | `container_id`, `try`                                                  |
| `Instagram post published`                                                                   | info           | `external_id`, `format`                                                |
| `Public media link served` / `Public media link asked for a missing file`                    | info / warning | `kind`, `converted_to_jpeg`, `size_bytes`                              |
| `Facebook returned an error`                                                                 | warning        | `action`, `status`, `code`, `subcode`, `facebook_message`              |

Tokens and signed links' signatures are never logged.

## Config (`.env`)

- Meta app: `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, `FACEBOOK_GRAPH_VERSION` (shared with Facebook).
- `INSTAGRAM_REDIRECT_URI` (default `/accounts/instagram/callback`, made absolute; add `http://localhost:8000/accounts/instagram/callback` to the Meta app).
- `INSTAGRAM_VIDEO_UPLOAD` = `resumable` (default) or `url`.
- `INSTAGRAM_STATUS_POLL_SECONDS` (15), `INSTAGRAM_STATUS_WAIT_SECONDS` (300; keep under the job timeout of 540).
- `INSTAGRAM_PUBLIC_BASE_URL`: optional fixed public address (a named tunnel); empty = read `INSTAGRAM_TUNNEL_URL_FILE` (default `storage/app/postloom-run/tunnel-url.txt`).
- `INSTAGRAM_PUBLIC_LINK_MINUTES` (15), `INSTAGRAM_RUPLOAD_BASE_URL` (default `https://rupload.facebook.com/ig-api-upload`).

## Tests

- `tests/Feature/Instagram/InstagramConnectTest.php`: redirect with the Instagram scopes and callback; every linked account saved with its Page token (Pages without Instagram skipped); reconnect updates in place; publishing permission off; no professional account; cancel; expired state; token exchange error; disabled without keys; daily check marks a revoked account.
- `tests/Feature/Instagram/InstagramPostRulesTest.php`: photo, carousel, Reel and Stories accepted; text-only, PDF, WebP, 8 MB, 11 items refused; single video needs Reel; feed ratio; Reel and Story limits; audio as Reel; hashtag and mention limits; `needsPublicLink()`; editor schedule; `describe()`.
- `tests/Feature/Instagram/InstagramPublisherTest.php`: photo via signed link with caption and alt text; carousel with a resumable video; Reel resumable upload and polling; photo Story; hand-off when the tunnel is down or no address is set; Reel works without the tunnel; `video_url` mode; audio as Reel; `ERROR` permanent; slow processing retryable; 9007 retried; daily limit stops before any container; 190 marks reconnect; dropped connection at publish is permanent; rule errors before any call; end to end through the job (published, and Ready for you).
- `tests/Feature/Instagram/PublicMediaTest.php`: signed link serves the photo without login; PNG turned into JPEG; unsigned, tampered and expired links refused; PDFs never served; tunnel requests only reach media and `/up`; tunnel address from the file; reachability check.
- `tests/Feature/Posts/ToPostTest.php` (Instagram hand-off) and `tests/Feature/Posts/CsvImportTest.php` (`instagram_format`).

Instagram is faked with a mocked Socialite provider, `Http::fake()`, `Sleep::fake()` and `Process::fake()` (ffmpeg).

## Known limits

- Live check still to do: one real post of each format after the Meta app has the Instagram permissions (guide steps 3–7). If Meta refuses `upload_type=resumable` for this app, set `INSTAGRAM_VIDEO_UPLOAD=url`.
- Photos need the tunnel tab; without it they go to **To post**. The quick tunnel address changes every start; a fixed address needs a named Cloudflare tunnel (free, with an account) and `INSTAGRAM_PUBLIC_BASE_URL`.
- No Reel cover image, user tags, location, collaborators or product tags yet.
- Meta allows 50–100 API posts per Instagram account per 24 hours (docs disagree); Postloom reads the real number from `content_publishing_limit`.
- Disconnecting in Postloom doesn't remove the app from Facebook; see `knowledge-base/facebook/README.md`.
