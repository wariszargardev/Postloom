# YouTube

## Purpose

Connect one or more **YouTube channels** to a workspace (piece 10) and upload videos to them (piece 11, see [Publishing](#publishing)). Each channel is its own account, so a workspace can hold several channels and the same Google login can add its brand channels one by one.

Setup guide for the Google Cloud project: `docs/guides/youtube/README.md`.

## Flow

1. **Accounts → YouTube → Connect** opens `GET /accounts/youtube/redirect`. The current workspace id goes into the session (`youtube_connect_workspace_id`). Socialite's `google` driver sends the browser to Google with:
    - scopes `openid profile email` (Socialite's defaults) + `youtube.upload` + `youtube.readonly`
    - `access_type=offline` (so Google returns a refresh token) and `prompt=consent select_account` (always show the account / channel picker and always return a refresh token)
2. The owner picks a Google account or brand channel and allows. Google redirects to `GET /accounts/youtube/callback?code=…&state=…`.
3. The callback checks `state` and swaps the code for tokens. Then:
    - If `youtube.upload` is not in the granted scopes (Google lets people untick it), the connection is refused.
    - If there is no refresh token, the connection is refused (fix: remove Postloom at myaccount.google.com/permissions and connect again).
4. `GET https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true` returns the channel picked in step 2. No channel → "This Google account has no YouTube channel".
5. `SocialAccountService::saveConnectedAccount()` saves it: provider `youtube`, type `channel`, `provider_account_id` = channel id (`UC…`), `name` = channel title, `handle` = `customUrl` without the `@`, avatar = default thumbnail, both tokens encrypted, `token_expires_at` = now + `expires_in` (about 1 hour).
6. Back to Accounts: "<channel> is connected."

Connecting the same channel again updates it in place. Connecting a different channel adds a new account.

### Token renewal

Google access tokens last about an hour; the refresh token keeps working until the owner revokes it, changes their password, or the Google app is in **Testing** mode (then it dies after 7 days).

- `YouTubeTokenService::accessTokenFor($account)` returns the saved token if it is valid for 5 more minutes, otherwise calls `refresh()`. Piece 11's publisher calls it before each upload.
- `refresh()` posts `grant_type=refresh_token` to `https://oauth2.googleapis.com/token` and saves the new access token and expiry (and the refresh token if Google sends a new one).
    - `invalid_grant` → `needs_reconnect = true`, `YouTubeTokenException` (not retryable).
    - Other 4xx (for example `invalid_client`: wrong `.env` keys) → `YouTubeTokenException` (not retryable); the channel is **not** marked.
    - 5xx or no connection → `YouTubeTokenException` (retryable).
- `accounts:check-tokens` (daily 09:00) also refreshes every YouTube channel, so a revoked login shows the Reconnect banner before a post fails.
- Accounts with a refresh token never count as "expired" or "expiring soon" from `token_expires_at` (`SocialAccount::canRefreshToken()`); the Accounts page shows "Token: renews automatically".

## Entry points

| Route                            | Name                        | Action                              |
| -------------------------------- | --------------------------- | ----------------------------------- |
| `GET /accounts/youtube/redirect` | `accounts.youtube.redirect` | `YouTubeConnectController@redirect` |
| `GET /accounts/youtube/callback` | `accounts.youtube.callback` | `YouTubeConnectController@callback` |

- `app/Services/YouTube/YouTubeConnectService.php`: `isConfigured()`, `redirect()`, `completeConnection()`; constants `DRIVER`, `SCOPES`, `UPLOAD_SCOPE`, `READ_SCOPE`.
- `app/Services/YouTube/YouTubeTokenService.php`, `YouTubeTokenException.php` (a `PublishException`, so the publisher can let it through).
- `app/Exceptions/YouTubeConnectException.php`: `uploadScopeMissing()`, `refreshTokenMissing()`, `noChannel()`.
- `app/Console/Commands/CheckAccountTokens.php`: the YouTube refresh check.
- `app/Enums/SocialProvider.php`: YouTube is available when the Google keys are set.

## Data

`social_accounts` (see `knowledge-base/workspaces-accounts/README.md`). YouTube rows: `account_type = channel`, `provider_account_id` = channel id, `refresh_token` set, `token_expires_at` about an hour ahead and moved forward on each refresh. `scopes` = the granted scopes as full URLs.

## Rules

- One account per channel. Brand channels under one Google login are connected one at a time (pick a different channel on the Google screen).
- Never ask for more than `youtube.upload` + `youtube.readonly`.
- Google app mode: **Testing** with each Google login added as a test user works now (logins end after 7 days; the daily check flags them for Reconnect). **Publish app** needs a home page, privacy page and an authorized domain you own; then logins stop expiring.
- A post's media is shared by all its accounts, so a YouTube post (1 video) can go to LinkedIn too, but not with LinkedIn-only images.

## Publishing

`YouTubePublisher` is the `Publisher` for YouTube; the scheduler job calls it once per channel. Editor rules and settings: `knowledge-base/posts/README.md` → YouTube.

### Flow

1. Check the media (`YouTubePostRules::mediaErrors()`) and that the title isn't empty.
2. Build the video resource: `snippet.title`, `snippet.description` (the account's text; `<` `>` become `‹` `›`), `snippet.tags` (parsed from the comma list, `#` stripped), `snippet.categoryId`, `status.privacyStatus`, `status.selfDeclaredMadeForKids`, `status.containsSyntheticMedia`.
3. **Short or not:** `format` `short`, or `auto` with a vertical/square video of up to 180 s (`YouTubePostRules::isShort()`). For a Short, `#Shorts` is added to the description when neither title nor description has it, and the link is `/shorts/<id>`. YouTube itself decides what shows on the Shorts shelf.
4. Audio is turned into an MP4 (`AudioToVideoConverter`) first and the temp file is deleted after.
5. `YouTubeUploader::upload()` (resumable protocol):
    - `POST https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status&notifySubscribers=…` with the resource as JSON and `X-Upload-Content-Length` / `X-Upload-Content-Type`; the `Location` header is the session URL. The token comes from `YouTubeTokenService::accessTokenFor()` (refreshed when under 5 minutes left). A 401 refreshes once and retries.
    - `PUT` the file in chunks of `YOUTUBE_UPLOAD_CHUNK_BYTES` (8 MB, rounded to 256 KB) with `Content-Range: bytes a-b/total`. `308` + `Range: bytes=0-n` → continue from `n+1`; `200`/`201` → the video id.
    - A lost or 5xx chunk: wait 2, 4, 8 s (`Sleep`), ask `Content-Range: bytes */total` what arrived, resume. After 3 failures, or on `404` (session expired), the job retries later from the start.
6. **Thumbnail / Short cover** (if `thumbnail_media_id` is an image of the same workspace): `POST /upload/youtube/v3/thumbnails/set?videoId=…` with the image bytes. For a **Short**, YouTube only accepts the cover once processing is done, so `YouTubeUploader::waitUntilProcessed()` first polls `GET /videos?part=processingDetails` every `YOUTUBE_PROCESSING_POLL_SECONDS` (10) for up to `YOUTUBE_PROCESSING_WAIT_SECONDS` (120) until `processingStatus = succeeded`. If the cover can't be set the video still counts as published; the reason is saved as a notice in `post_targets.last_error` ("Published, but …") and shown in the Delivery panel.
7. Link: `https://www.youtube.com/watch?v=<id>` or `https://www.youtube.com/shorts/<id>`.

### Errors (`YouTubeApiException`: `statusCode`, `reason`)

| YouTube                                                                                                    | Result                                                                      |
| ---------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| 401 twice (after one refresh)                                                                              | Permanent; channel set `needs_reconnect`                                    |
| `quotaExceeded`, `dailyLimitExceeded`, `uploadLimitExceeded`, `rateLimitExceeded`, `userRateLimitExceeded` | Permanent: "upload limit reached, retry tomorrow" (resets midnight Pacific) |
| Other 403                                                                                                  | Permanent: reconnect and tick both permissions                              |
| Other 4xx (`invalidTitle`, `invalidTags`, …)                                                               | Permanent, with YouTube's message and reason                                |
| 429 without a limit reason, 5xx, no connection                                                             | Retryable                                                                   |
| Token refresh `invalid_grant`                                                                              | Permanent; channel set `needs_reconnect` (`YouTubeTokenException`)          |

Default quota: 100 `videos.insert` calls per day per Google project; a thumbnail costs about 50 of the 10,000 daily units.

## Logging

Feature `youtube` for the connect flow; token and upload messages run on the caller's trace (`accounts` in the daily check, `publishing` in the publish job).

| Message                                                             | Level          | Context                                         |
| ------------------------------------------------------------------- | -------------- | ----------------------------------------------- |
| `Redirecting to Google to connect a YouTube channel`                | info           | `workspace_id`, `scopes`                        |
| `Google callback received`                                          | info           | `workspace_id`                                  |
| `Google did not grant the YouTube upload permission`                | warning        | `approved_scopes`                               |
| `Google did not return a refresh token`                             | warning        |                                                 |
| `YouTube channel lookup failed`                                     | warning        | `status`, `reason`, `message`                   |
| `Google account has no YouTube channel`                             | warning        |                                                 |
| `YouTube channel connected`                                         | info           | `account_id`, `channel_id`                      |
| `YouTube connection cancelled`                                      | info           | `error`                                         |
| `Google callback state did not match` / `YouTube connection failed` | error          | exception                                       |
| `YouTube token refreshed`                                           | info           | `account_id`, `token_expires_at`                |
| `YouTube access was revoked or expired, marked for reconnect`       | warning        | `account_id`, `reason`                          |
| `Google refused to refresh a YouTube token`                         | warning        | `account_id`, `status`, `error`                 |
| `Google could not be reached to refresh a YouTube token`            | warning        | `account_id`, exception                         |
| `YouTube token check failed, will try again tomorrow`               | warning        | `account_id`, exception                         |
| `Sending video to YouTube`                                          | info           | `media_kind`, `media_id`, `privacy`, `format`   |
| `YouTube upload started` / `YouTube upload finished`                | info           | `size_bytes`, `chunks` / `video_id`             |
| `YouTube upload chunk lost, checking what arrived`                  | warning        | `offset`, exception                             |
| `YouTube returned an error`                                         | warning        | `action`, `status`, `reason`, `youtube_message` |
| `YouTube could not be reached`                                      | warning        | `action`, exception                             |
| `YouTube thumbnail set` / `could not be set` / `image is missing`   | info / warning | `video_id`, `status`, `reason`                  |
| `YouTube video created`                                             | info           | `external_id`, `short`                          |

Tokens and emails are never logged.

## Config (`.env`)

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `YOUTUBE_REDIRECT_URI=http://localhost:8000/accounts/youtube/callback` (`config/services.php` → `google`)
- `GOOGLE_TOKEN_URL`, `YOUTUBE_API_BASE_URL`: defaults are Google's; only change for tests.
- `YOUTUBE_UPLOAD_CHUNK_BYTES=8388608` (rounded down to a multiple of 256 KB).
- `YOUTUBE_PROCESSING_WAIT_SECONDS=120`, `YOUTUBE_PROCESSING_POLL_SECONDS=10`: how long to wait for a Short to finish processing before setting its cover.
- `FFMPEG_PATH` (`config/postloom.php`): audio to video.

## Tests

- `tests/Feature/YouTube/YouTubeConnectTest.php`: redirect URL with scopes, offline access and picker; saves the picked channel into the starting workspace; second channel added and same channel updated; unticked upload scope refused; no refresh token refused; no channel; channel lookup error; cancel; expired state; disabled without keys; connect link and "renews automatically" on the Accounts page; guests blocked.
- `tests/Feature/YouTube/YouTubeTokenServiceTest.php`: refresh and save; reuse a valid token; refresh within 5 minutes of expiry; `invalid_grant` marks reconnect; 5xx retryable; `invalid_client` doesn't blame the channel; hourly expiry never flags a channel; daily check renews and marks revoked channels.

- `tests/Feature/YouTube/YouTubePublisherTest.php`: resumable start with metadata and headers; 256 KB chunks following `Range`; resume after a failed chunk; Short with `#Shorts` and shorts link; Auto detects a vertical Short; thumbnail set; refused thumbnail gives a notice; audio as video; expired token refreshed first; 401 refresh then reconnect; quota, upload limit, bad title, forbidden, 500, 429 mapping; empty title; end to end through the job with the notice saved.
- `tests/Feature/YouTube/YouTubePostRulesTest.php`: schedule with settings; title required; exactly one video; Short too long; `<>` in title and too many tags; thumbnail must be an image; YouTube tag length counting; settings in the editor props.

Google is faked with a mocked Socialite provider and `Http::fake()`, ffmpeg with `Process::fake()`, waits with `Sleep::fake()`.

## Known limits

- Disconnect in Postloom doesn't revoke Google access. Do that at myaccount.google.com/permissions.
- Google limits how many refresh tokens one app can hold per Google login; connecting the same login very many times can invalidate the oldest ones (only matters with lots of brand channels).
- The daily check uses one token request per channel per day (no YouTube quota cost).
- Uploads must finish within the job's 540 s timeout; on a slow line a video near the 500 MB limit may not. A failed try starts a fresh upload.
- No YouTube-side scheduling (`publishAt`) and no captions or playlists yet.
- Custom thumbnails need a phone-verified channel (youtube.com/verify).
