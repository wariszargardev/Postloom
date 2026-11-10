# LinkedIn

## Purpose

Connect one or more **personal LinkedIn profiles** to a workspace (piece 03) and post to them (piece 08, see [Publishing](#publishing)). Company pages come in `feat/linkedin-pages`.

## LinkedIn app setup (one time)

Step-by-step guide with screenshots and video: `docs/guides/linkedin/README.md`. Short version:

1. Open <https://www.linkedin.com/developers/apps> → **Create app**. LinkedIn asks for a Company Page to link the app to (any Page you admin, even a dummy one) and a logo. Verify the app from that Page when asked.
2. **Products** tab: add **Sign In with LinkedIn using OpenID Connect** and **Share on LinkedIn**. Both are self-serve and approved immediately.
3. **Auth** tab → **Authorized redirect URLs for your app**: add exactly `http://localhost:8000/accounts/linkedin/callback`.
4. **Auth** tab → **OAuth 2.0 scopes**: you should see `openid`, `profile`, `email`, `w_member_social`.
5. Copy **Client ID** and **Primary Client Secret** into `.env`:
    ```
    LINKEDIN_CLIENT_ID=...
    LINKEDIN_CLIENT_SECRET=...
    LINKEDIN_REDIRECT_URI=http://localhost:8000/accounts/linkedin/callback
    ```
6. Run `php artisan config:clear`, open **Accounts** → LinkedIn → **Connect**.

The same app works for every LinkedIn profile you want to connect: each profile owner logs in and approves once.

## Flow

1. **Accounts → LinkedIn → Connect** opens `GET /accounts/linkedin/redirect`. The current workspace id is stored in the session (`linkedin_connect_workspace_id`), and Socialite sends the browser to LinkedIn with scopes `openid profile email w_member_social` and a random `state`.
2. The owner approves on LinkedIn. LinkedIn redirects to `GET /accounts/linkedin/callback?code=…&state=…`.
3. The callback checks `state`, swaps the code for a token, and reads the profile from `https://api.linkedin.com/v2/userinfo` (`sub`, `name`, `picture`, `email`).
4. LinkedIn returns the granted scopes comma-separated in one string (`"email,openid,profile,w_member_social"`); `normalizeScopes()` splits them on commas and spaces. If `w_member_social` is then missing, the connection is refused (`LinkedInScopeMissingException`).
5. `SocialAccountService::saveConnectedAccount()` saves it into the workspace from step 1 (or the current one if that workspace is gone): provider `linkedin`, type `profile`, `provider_account_id` = `sub`, token encrypted, `token_expires_at` = now + `expires_in` (about 60 days).
6. The browser goes back to Accounts with a toast: "<name> is connected."

**Reconnect** (shown on accounts that expire within 7 days or need reconnect) runs the same flow. The same `sub` updates the existing row.

## Entry points

| Route                             | Name                         | Action                               |
| --------------------------------- | ---------------------------- | ------------------------------------ |
| `GET /accounts/linkedin/redirect` | `accounts.linkedin.redirect` | `LinkedInConnectController@redirect` |
| `GET /accounts/linkedin/callback` | `accounts.linkedin.callback` | `LinkedInConnectController@callback` |

- `app/Services/LinkedIn/LinkedInConnectService.php`: `isConfigured()`, `redirect()`, `completeConnection()`; constants `DRIVER`, `SCOPES`, `PUBLISH_SCOPE`.
- `app/Exceptions/LinkedInScopeMissingException.php`
- `app/Enums/SocialProvider.php`: LinkedIn is available only when the keys are set; `connectRouteName()`.
- `app/Console/Commands/CheckAccountTokens.php` (`accounts:check-tokens`, daily 09:00 in `POSTLOOM_TIMEZONE`).
- `resources/js/components/AccountsAttentionBanner.vue` (shown on every app page when `accountsNeedingAttention` > 0).

## Data

Stored in `social_accounts` (see `knowledge-base/workspaces-accounts/README.md`). For LinkedIn: `provider_account_id` is the member id (`sub`); the post author URN in piece 08 is `urn:li:person:<sub>`. `refresh_token` stays null (LinkedIn only issues refresh tokens to approved partner apps).

## Rules

- Personal profiles only (`w_member_social`). Company pages need the Community Management API on a separate app; that's `feat/linkedin-pages`.
- Tokens last about 60 days and cannot be renewed silently, so the owner reconnects. Warnings start 7 days before.
- `accounts:check-tokens` marks expired tokens `needs_reconnect = true`; it never deletes accounts.
- Cancel on LinkedIn (`?error=user_cancelled_…`) saves nothing.

## Logging

Feature `linkedin` (connect flow) and `accounts` (save and token check).

| Message                                              | Level   | Context                                                           |
| ---------------------------------------------------- | ------- | ----------------------------------------------------------------- |
| `Redirecting to LinkedIn to connect a profile`       | info    | `workspace_id`, `scopes`                                          |
| `LinkedIn callback received`                         | info    | `workspace_id`                                                    |
| `LinkedIn did not grant the posting permission`      | warning | `approved_scopes`                                                 |
| `LinkedIn profile connected`                         | info    | `account_id`, `token_expires_at`                                  |
| `LinkedIn connection cancelled`                      | info    | `error`                                                           |
| `LinkedIn callback state did not match`              | error   | exception                                                         |
| `LinkedIn connection failed`                         | error   | exception                                                         |
| `Social account connected` / `reconnected`           | info    | `account_id`, `provider`, `token_expires_at` (feature `accounts`) |
| `Social account token expired, marked for reconnect` | warning | `account_id`, `provider`, `token_expires_at` (feature `accounts`) |
| `Social account token expires soon`                  | warning | same                                                              |
| `Social account token check finished`                | info    | `marked_for_reconnect`, `expiring_soon`                           |

## Config (`.env`)

- `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET`, `LINKEDIN_REDIRECT_URI` (`config/services.php` → `linkedin-openid`)
- `LINKEDIN_API_VERSION=202610` (`YYYYMM`; LinkedIn keeps each version about a year, so bump it yearly), `LINKEDIN_VIDEO_WAIT_SECONDS=300`, `LINKEDIN_VIDEO_POLL_SECONDS=5`, `LINKEDIN_API_BASE_URL` (default `https://api.linkedin.com/rest`)
- `FFMPEG_PATH` (`config/postloom.php`): used to turn audio into video
- `POSTLOOM_TIMEZONE=Asia/Karachi` (`config/postloom.php`): timezone of the 09:00 token check

## Tests

- `tests/Feature/LinkedIn/LinkedInConnectTest.php`: redirect URL and scopes, saves into the starting workspace, reconnect updates in place, cancel, missing posting scope, expired state, disabled without keys, connect link with keys, guests blocked.
- `tests/Feature/Accounts/AccountTokenAttentionTest.php`: daily command marks expired accounts, command is scheduled, banner count across the owner's workspaces only.

LinkedIn is mocked in tests (`Socialite::shouldReceive('driver')`); no real calls.

## Publishing

`LinkedInPublisher` is the `Publisher` for LinkedIn. The scheduler job (`knowledge-base/scheduler/README.md`) calls it once per account.

### Flow

1. Check the media with `LinkedInPostRules::mediaErrors()` and the target's `settings.visibility` (`PUBLIC` or `CONNECTIONS`).
2. Upload the media (`LinkedInMediaUploader`), owner `urn:li:person:<provider_account_id>`:

    | Media       | Calls                                                                                                                                                                                                                    | Post `content`                        |
    | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------- |
    | 1 image     | `POST /images?action=initializeUpload` → `PUT` bytes to `uploadUrl` (with token)                                                                                                                                         | `media: {id, altText?}`               |
    | 2–20 images | same, per image, in `sort_order`                                                                                                                                                                                         | `multiImage.images: [{id, altText?}]` |
    | Video       | `POST /videos?action=initializeUpload` (`fileSizeBytes`) → `PUT` each part (`firstByte`–`lastByte`, no token), keep each `ETag` → `POST /videos?action=finalizeUpload` → `GET /videos/{urn}` every 5 s until `AVAILABLE` | `media: {id, title}`                  |
    | PDF         | `POST /documents?action=initializeUpload` → `PUT` bytes                                                                                                                                                                  | `media: {id, title}`                  |
    | Audio       | `AudioToVideoConverter` (ffmpeg, black 1280×720 frame, AAC) → temp MP4 in `storage/app/tmp`, uploaded as a video, then deleted                                                                                           | `media: {id, title}`                  |

    `title` is the file name without its extension.

3. `LinkedInCommentaryFormatter` escapes `| { } @ [ ] ( ) < > # \ * _ ~` and turns `#word` into `{hashtag|\#|word}`. A `#` inside a word (`C#`) or on its own stays plain text.
4. `POST /posts` with `author`, `commentary`, `visibility`, `distribution.feedDistribution = MAIN_FEED`, `lifecycleState = PUBLISHED`, `isReshareDisabledByAuthor = false`, and `content` if there is media.
5. The post id is the `x-restli-id` response header (`urn:li:share:…` or `urn:li:ugcPost:…`). The link is `https://www.linkedin.com/feed/update/<id>/`.

Every call goes to `https://api.linkedin.com/rest` with `Authorization: Bearer`, `Linkedin-Version: <LINKEDIN_API_VERSION>` and `X-Restli-Protocol-Version: 2.0.0` (`LinkedInApiClient`).

### Errors (`LinkedInApiException`, a `PublishException` with `statusCode` and `serviceErrorCode`)

| LinkedIn                           | Result                                                            |
| ---------------------------------- | ----------------------------------------------------------------- |
| 401                                | Permanent; the account is set `needs_reconnect = true`            |
| 403                                | Permanent: "check the Share on LinkedIn product"                  |
| Other 4xx (400, 422 …)             | Permanent, with LinkedIn's `message`                              |
| 429, 5xx, no connection            | Retryable (job retries after 60 s, then 300 s)                    |
| Video `PROCESSING_FAILED`          | Permanent                                                         |
| Video still processing after 300 s | Retryable (the next try uploads it again)                         |
| 201 without `x-restli-id`          | Permanent (the post may exist; check the profile before retrying) |

### Publishing logs

Logged on the job's trace (feature `publishing`); the publisher never starts its own.

| Message                                           | Level   | Context                                                      |
| ------------------------------------------------- | ------- | ------------------------------------------------------------ |
| `Sending post to LinkedIn`                        | info    | `media_kind`, `media_count`, `visibility`                    |
| `LinkedIn media uploaded`                         | info    | `kind`, `media_id`, `media_urn`                              |
| `LinkedIn video upload started`                   | info    | `size_bytes`                                                 |
| `LinkedIn video uploaded, waiting for processing` | info    | `media_urn`, `parts`                                         |
| `LinkedIn video is ready`                         | info    | `media_urn`, `checks`                                        |
| `Audio turned into a video`                       | info    | `size_bytes`                                                 |
| `Audio could not be turned into a video`          | warning | `exit_code`, `error`                                         |
| `LinkedIn post created`                           | info    | `external_id`                                                |
| `LinkedIn returned an error`                      | warning | `action`, `status`, `service_error_code`, `linkedin_message` |
| `LinkedIn could not be reached`                   | warning | `action`, exception                                          |

Tokens are never logged.

### Publishing tests

- `tests/Feature/LinkedIn/LinkedInPublisherTest.php`: text post headers and body; escaping, hashtags, override text and visibility; one image with alt text; three images as multi-image in order; video in 2 parts with finalize and PROCESSING → AVAILABLE; video processing failed; PDF; audio converted, posted and temp file deleted; 401 marks reconnect; 403 and 422 permanent; 429 / 500 / 503 retryable; end to end through the job to Published.
- `tests/Unit/Services/LinkedInCommentaryFormatterTest.php`: plain text, every reserved character, hashtags, `#` inside words, non-English hashtags.

LinkedIn is faked with `Http::fake()`, ffmpeg with `Process::fake()`, waits with `Sleep::fake()`.

## Known limits

- Publishing posts straight to the profile; there is no "draft on LinkedIn" step.
- A video that is still processing after the wait is uploaded again on the retry.
- Image pixel limit (36,152,320) and video length are checked by LinkedIn, not before upload.
- The redirect URL must match exactly. Running on another host or port needs that URL added in the LinkedIn app and in `LINKEDIN_REDIRECT_URI`.
- Disconnect in Postloom does not revoke the app on LinkedIn. Do that in LinkedIn → Settings → Data privacy → Permitted services.
- The daily check only runs while `start.ps1` (scheduler) is running.
