# YouTube

## Purpose

Connect one or more **YouTube channels** to a workspace (piece 10). Each channel is its own account, so a workspace can hold several channels and the same Google login can add its brand channels one by one. Uploading videos comes in piece 11 (`feat/11-youtube-publish`).

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
- Google app mode: use **In production** (unverified is fine for your own channels; you see a "Google hasn't verified this app" warning once). In **Testing** mode logins die after 7 days.
- Posting to YouTube stays blocked by the post editor ("posting is not available yet") until piece 11 adds `YouTubePostRules`.

## Logging

Feature `youtube` for the connect flow; token messages run on the caller's trace (`accounts` in the daily check, `publishing` in piece 11).

| Message                                                             | Level   | Context                          |
| ------------------------------------------------------------------- | ------- | -------------------------------- |
| `Redirecting to Google to connect a YouTube channel`                | info    | `workspace_id`, `scopes`         |
| `Google callback received`                                          | info    | `workspace_id`                   |
| `Google did not grant the YouTube upload permission`                | warning | `approved_scopes`                |
| `Google did not return a refresh token`                             | warning |                                  |
| `YouTube channel lookup failed`                                     | warning | `status`, `reason`, `message`    |
| `Google account has no YouTube channel`                             | warning |                                  |
| `YouTube channel connected`                                         | info    | `account_id`, `channel_id`       |
| `YouTube connection cancelled`                                      | info    | `error`                          |
| `Google callback state did not match` / `YouTube connection failed` | error   | exception                        |
| `YouTube token refreshed`                                           | info    | `account_id`, `token_expires_at` |
| `YouTube access was revoked or expired, marked for reconnect`       | warning | `account_id`, `reason`           |
| `Google refused to refresh a YouTube token`                         | warning | `account_id`, `status`, `error`  |
| `Google could not be reached to refresh a YouTube token`            | warning | `account_id`, exception          |
| `YouTube token check failed, will try again tomorrow`               | warning | `account_id`, exception          |

Tokens and emails are never logged.

## Config (`.env`)

- `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `YOUTUBE_REDIRECT_URI=http://localhost:8000/accounts/youtube/callback` (`config/services.php` → `google`)
- `GOOGLE_TOKEN_URL`, `YOUTUBE_API_BASE_URL`: defaults are Google's; only change for tests.

## Tests

- `tests/Feature/YouTube/YouTubeConnectTest.php`: redirect URL with scopes, offline access and picker; saves the picked channel into the starting workspace; second channel added and same channel updated; unticked upload scope refused; no refresh token refused; no channel; channel lookup error; cancel; expired state; disabled without keys; connect link and "renews automatically" on the Accounts page; guests blocked.
- `tests/Feature/YouTube/YouTubeTokenServiceTest.php`: refresh and save; reuse a valid token; refresh within 5 minutes of expiry; `invalid_grant` marks reconnect; 5xx retryable; `invalid_client` doesn't blame the channel; hourly expiry never flags a channel; daily check renews and marks revoked channels.

Google is faked with a mocked Socialite provider and `Http::fake()`.

## Known limits

- Disconnect in Postloom doesn't revoke Google access. Do that at myaccount.google.com/permissions.
- Google limits how many refresh tokens one app can hold per Google login; connecting the same login very many times can invalidate the oldest ones (only matters with lots of brand channels).
- The daily check uses one token request per channel per day (no YouTube quota cost).
