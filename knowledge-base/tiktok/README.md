# TikTok

## Purpose

Connect one or more **TikTok accounts** to a workspace (piece 16) and post videos to them (piece 17, see [Publishing](#publishing)). Each TikTok login is its own account, so a workspace can hold several.

Setup guide for the TikTok developer app: `docs/guides/tiktok/README.md`.

## Flow

1. **Accounts → TikTok → Connect** opens `GET /accounts/tiktok/redirect`. The current workspace id (`tiktok_connect_workspace_id`) and a random 40-character `state` (`tiktok_connect_state`) go into the session. The browser goes to `https://www.tiktok.com/v2/auth/authorize/` with:
    - `client_key`, `response_type=code`, `redirect_uri` (`TIKTOK_REDIRECT_URI`, made absolute with `url()`), `state`
    - `scope=user.info.basic,user.info.profile,video.upload,video.publish` (comma separated)
2. The owner logs in to TikTok and allows. TikTok redirects to `GET /accounts/tiktok/callback?code=…&state=…` (or `?error=…` when cancelled).
3. The callback:
    - `error` or no `code` → "TikTok connection was cancelled."
    - `state` must equal the session value (`hash_equals`), otherwise "The TikTok login expired". The state is single-use (`session()->pull`).
    - `POST https://open.tiktokapis.com/v2/oauth/token/` (form: `client_key`, `client_secret`, `code`, `grant_type=authorization_code`, `redirect_uri`) returns `access_token`, `refresh_token`, `expires_in`, `refresh_expires_in`, `open_id`, `scope`.
    - If neither `video.publish` nor `video.upload` is in `scope`, the connection is refused.
4. `GET https://open.tiktokapis.com/v2/user/info/?fields=open_id,union_id,avatar_url,display_name,username` with the Bearer token. TikTok answers with `data.user` and `error.code` (`ok` on success).
5. `SocialAccountService::saveConnectedAccount()` saves it: provider `tiktok`, type `profile`, `provider_account_id` = `open_id`, `name` = `display_name`, `handle` = `username`, avatar, both tokens encrypted, `token_expires_at` = now + `expires_in` (24 hours on the first real connect, 2026-11-11), `scopes` = granted scopes.
6. Back to Accounts: "<name> is connected."

Connecting the same TikTok login again updates it in place (same `open_id`). Another login adds a new account.

### Token renewal

TikTok access tokens last up to **24 hours** (the docs say 2 hours; the first real connect on 2026-11-11 returned 24 hours, and Postloom always uses `expires_in`); refresh tokens about **180 days**. Every refresh returns a **new refresh token** and the old one stops working, so both are saved every time.

- `TikTokTokenService::accessTokenFor($account)` returns the saved token if it is valid for 5 more minutes, otherwise calls `refresh()`. The publisher (`TikTokApiClient`) calls it before every API call.
- `refresh()` posts `grant_type=refresh_token` to `/v2/oauth/token/` and saves the new access token, refresh token and expiry.
    - `invalid_grant`, `access_token_invalid`, `scope_not_authorized` → `needs_reconnect = true`, `TikTokTokenException` (not retryable).
    - Other 4xx (for example `invalid_client`: wrong `.env` keys) → `TikTokTokenException` (not retryable); the account is **not** marked.
    - 5xx, 429 or no connection → `TikTokTokenException` (retryable).
- `accounts:check-tokens` (daily 09:00) refreshes every TikTok account. That keeps the 180-day refresh token rolling forward, so it never runs out while Postloom runs at least every few months, and a revoked login shows Reconnect before a post fails.
- Like YouTube, accounts with a refresh token never count as "expired" or "expiring soon" from `token_expires_at`.

## Entry points

| Route                           | Name                       | Action                             |
| ------------------------------- | -------------------------- | ---------------------------------- |
| `GET /accounts/tiktok/redirect` | `accounts.tiktok.redirect` | `TikTokConnectController@redirect` |
| `GET /accounts/tiktok/callback` | `accounts.tiktok.callback` | `TikTokConnectController@callback` |

- `app/Services/TikTok/TikTokConnectService.php`: `isConfigured()`, `redirectUri()`, `newState()`, `authorizationUrl()`, `completeConnection()`; constants `SCOPES`, `PUBLISH_SCOPE`, `UPLOAD_SCOPE`, `USER_FIELDS`. Plain HTTP, no Socialite driver (Laravel has none for TikTok and no package was added).
- `app/Services/TikTok/TikTokTokenService.php`, `TikTokTokenException.php` (a `PublishException`).
- `app/Exceptions/TikTokConnectException.php`: `stateMismatch()`, `publishScopeMissing()`, `tokenExchangeFailed()`, `profileLookupFailed()`.
- `app/Console/Commands/CheckAccountTokens.php`: the TikTok refresh.
- `app/Enums/SocialProvider.php`: TikTok is available when the TikTok keys are set ("Profile (videos)").

## Data

`social_accounts` (see `knowledge-base/workspaces-accounts/README.md`). TikTok rows: `account_type = profile`, `provider_account_id` = `open_id` (unique per app and user), `handle` = username, `refresh_token` set, `token_expires_at` = now + `expires_in` and moved forward on each refresh. No new columns.

## Rules

- **Redirect URI must be https and registered in Login Kit exactly** (no `http://localhost`, no query string). Use Herd's `https://postloom.test/accounts/tiktok/callback` (`herd secure postloom`). TikTok accepted this `.test` URL on 2026-11-11 (sandbox). If it ever refuses it, use a tunnel (Cloudflare Tunnel / ngrok) and set `TIKTOK_REDIRECT_URI` and `APP_URL` to it.
- The app stays in **Sandbox** (or unaudited production) for personal use: only TikTok accounts added as target users can log in, and unaudited apps can only post **privately** (`SELF_ONLY`) to **private** accounts, at most 5 posting users per 24 hours. "Send to TikTok inbox" is the way to post publicly without an audit.
- Never ask for more than the four scopes above.
- No PKCE: TikTok's web Login Kit uses `state` only.

## Publishing

`TikTokPublisher` is the `Publisher` for TikTok (piece 17); the scheduler job calls it once per account. Editor rules and settings: `knowledge-base/posts/README.md` → TikTok.

**How to post** (`mode`), decided 2026-11-11 after real tests:

| Mode                                     | What happens                                                                                                                                  | Use                                |
| ---------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| `manual` (default) "I'll post it myself" | No TikTok call. The account becomes **Ready for you** and the post waits under **To post** (see Manual hand-off)                              | Public posts with full reach       |
| `direct`                                 | API direct post. Unaudited app: only `SELF_ONLY`, and the TikTok account must be **private** at posting time (`unaudited_client_…` otherwise) | Private test posts                 |
| `inbox`                                  | API inbox upload. TikTok reports `SEND_TO_USER_INBOX`, but in Sandbox the draft never reached the TikTok app (post 21, 2026-11-11)            | Only after TikTok approves the app |

TikTok's audit can't lift these limits for Postloom: the Content Sharing Guidelines list "a utility tool to help upload contents to the account(s) you or your team manages" as not acceptable.

### Manual hand-off

1. At the post time the job calls `TikTokPublisher`, which for `manual` returns `PublishResult(needsManualPost: true)` without any API call. The job sets the account to `ready_to_post` ("Ready for you"), no `published_at`; `PostStatusResolver` counts it as delivered.
2. **To post** (`GET /to-post`, sidebar item with a count badge, plus a banner on every page) lists the workspace's `ready_to_post` accounts (`ManualHandoffService::waiting()`), oldest first: video thumbnail, account, due time, caption.
3. Buttons: **Download video** (`GET /to-post/{postTarget}/video`, file named from the post title, e.g. `kela-kaala-kyun.mp4`), **Copy caption**, **Open TikTok Studio** (`https://www.tiktok.com/tiktokstudio/upload`).
4. The owner uploads it in TikTok Studio on the PC (the phone can't open `localhost`), then **Mark as posted** (`POST /to-post/{postTarget}/posted`, optional TikTok link validated by `MarkPostedRequest`). The account becomes **Published** with that link (`ManualHandoffService::markPosted()`), and the post status is resolved again.
5. Only the workspace owner can download or mark (`PostPolicy` view / update); an account that isn't waiting gives 409.

### Flow (direct and inbox)

1. Check the media and settings (`TikTokPostRules::mediaErrors()` + `targetErrors()`): exactly one video or audio file.
2. **Direct post only:** `POST /v2/post/publish/creator_info/query/`. The chosen privacy must be in `privacy_level_options` (else a permanent error listing the allowed ones) and the video no longer than `max_video_post_duration_sec`. Then `post_info` is built: `title` (the caption), `privacy_level`, `disable_comment` / `disable_duet` / `disable_stitch` (off unless the setting allows it and TikTok doesn't disable it), `brand_organic_toggle` (Your brand), `brand_content_toggle` (Branded), `is_aigc`.
3. Audio is turned into an MP4 (`AudioToVideoConverter`) and the temp file deleted after.
4. `TikTokUploader::upload()`:
    - Init: `POST /v2/post/publish/video/init/` (direct, with `post_info`) or `POST /v2/post/publish/inbox/video/init/` (inbox, no caption allowed), `source_info` = `FILE_UPLOAD`, `video_size`, `chunk_size`, `total_chunk_count`. Returns `publish_id` and `upload_url` (valid 1 hour).
    - Chunks (`TikTokUploader::chunkPlan()`): up to 64 MB → one chunk of the whole file; bigger → `TIKTOK_UPLOAD_CHUNK_BYTES` (10 MB, kept within 5–64 MB), count = size ÷ chunk rounded down, the last chunk takes the rest. `PUT upload_url` with `Content-Range: bytes a-b/total` and the video's content type; 206/201 = OK. A lost or 5xx chunk is retried twice (2 s, 4 s); then, or on a 4xx (403 = address expired), the job retries from the start (nothing is posted until all chunks arrive).
5. `TikTokUploader::waitForResult()` polls `POST /v2/post/publish/status/fetch/` every `TIKTOK_STATUS_POLL_SECONDS` (10) for up to `TIKTOK_STATUS_WAIT_SECONDS` (180). Polling errors are logged, never thrown (the video is already with TikTok; a retry would post it twice).

| Final status         | Result                                                                                                                                                                                                                    |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PUBLISH_COMPLETE`   | Published. With `publicaly_available_post_id` (public posts after moderation): link `https://www.tiktok.com/@<handle>/video/<id>`. Without: profile link + note "Published as Only me" (or "TikTok is still checking it") |
| `SEND_TO_USER_INBOX` | Published, link to the profile, note "Sent to your TikTok inbox. Open the TikTok app to post it. Caption to paste: …"                                                                                                     |
| `FAILED`             | Permanent failure with TikTok's `fail_reason`                                                                                                                                                                             |
| Still processing     | Published with the `publish_id` and a "still processing" note                                                                                                                                                             |

Notes are saved in `post_targets.last_error` and shown in the Delivery panel, like YouTube's "Published, but…".

### Errors (`TikTokApiException`: `statusCode`, `errorCode`, `logId`)

`TikTokApiClient` sends every call with `TikTokTokenService::accessTokenFor()`; a 401 or `access_token_invalid` refreshes once and repeats the call.

| TikTok                                                                                                                                                           | Result                                                                  |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| 429, `rate_limit_exceeded`, 5xx, `internal_error`, no connection (before the upload is complete)                                                                 | Retryable                                                               |
| `access_token_invalid` after a refresh, `scope_not_authorized`, 401                                                                                              | Permanent; account set `needs_reconnect`                                |
| `unaudited_client_can_only_post_to_private_accounts`                                                                                                             | Permanent: set the TikTok account private or use inbox                  |
| `reached_active_user_cap`, `spam_risk_too_many_posts`, `spam_risk_too_many_pending_share`, `spam_risk_user_banned_from_posting`, `privacy_level_option_mismatch` | Permanent, with a plain message (`TikTokApiClient::PERMANENT_MESSAGES`) |
| Other 4xx                                                                                                                                                        | Permanent, with TikTok's code and message                               |

Limits: about 15 posts a day per account across all apps; init is limited to 6 calls a minute per token, status to 30.

## Logging

Feature `tiktok` for the connect flow; token messages run on the caller's trace (`accounts` in the daily check, `publishing` in the publish job).

| Message                                                       | Level   | Context                                                      |
| ------------------------------------------------------------- | ------- | ------------------------------------------------------------ |
| `Redirecting to TikTok to connect an account`                 | info    | `workspace_id`, `scopes`, `redirect_uri`                     |
| `TikTok callback received`                                    | info    | `workspace_id`                                               |
| `TikTok connection cancelled`                                 | info    | `error`, `error_description`                                 |
| `TikTok callback state did not match`                         | warning |                                                              |
| `TikTok code exchange failed`                                 | warning | `status`, `error`, `error_description`, `log_id`             |
| `TikTok did not grant a video permission`                     | warning | `granted_scopes`                                             |
| `TikTok user info lookup failed`                              | warning | `status`, `error_code`, `message`, `log_id`                  |
| `TikTok user info had no open_id`                             | warning |                                                              |
| `TikTok account connected`                                    | info    | `account_id`, `granted_scopes`                               |
| `TikTok connection failed`                                    | error   | exception                                                    |
| `TikTok token refreshed`                                      | info    | `account_id`, `token_expires_at`, `refresh_expires_in`       |
| `TikTok access was revoked or expired, marked for reconnect`  | warning | `account_id`, `reason`                                       |
| `TikTok refused to refresh a token`                           | warning | `account_id`, `status`, `error`, `log_id`                    |
| `TikTok could not be reached to refresh a token`              | warning | `account_id`, exception                                      |
| `TikTok token check failed, will try again tomorrow`          | warning | `account_id`, exception                                      |
| `Sending video to TikTok`                                     | info    | `media_kind`, `media_id`, `mode`, `privacy`                  |
| `TikTok creator info checked`                                 | info    | `privacy_level_options`, `max_video_post_duration_sec`       |
| `TikTok upload started` / `TikTok upload finished`            | info    | `publish_id`, `size_bytes`, `chunks`, `mode`                 |
| `TikTok upload chunk lost` / `TikTok refused an upload chunk` | warning | `chunk`, `status`, exception                                 |
| `TikTok post status`                                          | info    | `publish_id`, `status`                                       |
| `TikTok status check failed`                                  | warning | `publish_id`, exception                                      |
| `TikTok video sent to inbox` / `TikTok video published`       | info    | `publish_id`, `post_id`, `privacy`                           |
| `TikTok could not post the video`                             | warning | `publish_id`, `fail_reason`                                  |
| `TikTok was still processing the video`                       | warning | `publish_id`, `status`                                       |
| `TikTok returned an error`                                    | warning | `action`, `status`, `error_code`, `tiktok_message`, `log_id` |
| `TikTok could not be reached`                                 | warning | `action`, `account_id`, exception                            |
| `TikTok rejected the token, refreshing once`                  | info    | `action`                                                     |
| `TikTok post handed off to you`                               | info    | `media_id`                                                   |
| `Hand-off video downloaded`                                   | info    | `media_id`                                                   |
| `Hand-off marked as posted`                                   | info    | `account_id`, `has_link`                                     |

`log_id` is TikTok's request id; quote it to TikTok support. Tokens and codes are never logged.

## Config (`.env`)

- `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` (Login Kit app keys), `TIKTOK_REDIRECT_URI=https://postloom.test/accounts/tiktok/callback` (`config/services.php` → `tiktok`).
- `TIKTOK_AUTHORIZE_URL`, `TIKTOK_API_BASE_URL`: defaults are TikTok's; only change for tests.
- `TIKTOK_UPLOAD_CHUNK_BYTES=10485760` (files over 64 MB; kept within 5–64 MB).
- `TIKTOK_STATUS_WAIT_SECONDS=180`, `TIKTOK_STATUS_POLL_SECONDS=10`: how long the job waits for TikTok to finish.
- `FFMPEG_PATH` (`config/postloom.php`): audio to video.

## Tests

- `tests/Feature/TikTok/TikTokConnectTest.php`: redirect URL (client key, scopes, redirect URI, state in session), missing keys, connect into the starting workspace, reconnect updates the same account, forged state, cancel, missing video scope, refused code exchange, provider availability.
- `tests/Feature/TikTok/TikTokTokenServiceTest.php`: refresh saves the new refresh token, valid token reused, `invalid_grant` marks reconnect, 5xx is retryable, the daily check refreshes TikTok and counts a dead login.
- `tests/Feature/TikTok/TikTokPostRulesTest.php`: video and audio allowed; text only, photos, PDFs, two videos, AVI, too short / long refused; branded + Only me refused; caption over 2,200; editor props.
- `tests/Feature/Posts/ToPostTest.php`: To post lists only this workspace's waiting accounts, download with a clean name, another owner gets 403, Mark as posted with a link, without a link, a non-TikTok link refused, 409 when not waiting.
- `tests/Feature/TikTok/TikTokPublisherTest.php`: manual mode (no API call, Ready for you, post counted as delivered), inbox upload (no `post_info`, Content-Range, caption in the note, one poll), direct post (`post_info` fields), public post link, privacy not allowed by creator info, `FAILED`, still processing, unaudited private-account error, 429/5xx retryable, token refresh and repeat, `scope_not_authorized` marks reconnect, audio to video, chunk plan, end to end through the job.

## Known limits

- Videos only: photo posts need TikTok to pull images from a public verified domain (`PULL_FROM_URL`), which a local Postloom doesn't have.
- Inbox uploads can't carry a caption (TikTok API limit); the caption is put in the Delivery note to paste in the app.
- The editor shows fixed privacy options; the publisher checks them against TikTok's creator info at publish time. A TikTok audit would need the options loaded live in the editor.
- TikTok's token, refresh and revoke reference pages could not be read during research (403); field names follow the OAuth guide and TikTok's v2 conventions. Check `log_id` and `error` in the logs if a real connect fails.
- Disconnecting in Postloom does not revoke the token at TikTok; remove the app in TikTok → Settings → Security → Manage app permissions.
- Sandbox only lets **target users** log in. Any other TikTok account gets TikTok's "Something went wrong … non_sandbox_target" page before Postloom is reached; add it under Sandbox settings → Target Users, or log in as a listed account.
