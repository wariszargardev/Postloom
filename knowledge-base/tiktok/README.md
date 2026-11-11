# TikTok

## Purpose

Connect one or more **TikTok accounts** to a workspace (piece 16). Publishing videos to them is piece 17 (`feat/17-tiktok-publish`). Each TikTok login is its own account, so a workspace can hold several.

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
5. `SocialAccountService::saveConnectedAccount()` saves it: provider `tiktok`, type `profile`, `provider_account_id` = `open_id`, `name` = `display_name`, `handle` = `username`, avatar, both tokens encrypted, `token_expires_at` = now + `expires_in` (about 2 hours), `scopes` = granted scopes.
6. Back to Accounts: "<name> is connected."

Connecting the same TikTok login again updates it in place (same `open_id`). Another login adds a new account.

### Token renewal

TikTok access tokens last about **2 hours**; refresh tokens about **180 days**. Every refresh returns a **new refresh token** and the old one stops working, so both are saved every time.

- `TikTokTokenService::accessTokenFor($account)` returns the saved token if it is valid for 5 more minutes, otherwise calls `refresh()`. Piece 17's publisher will call it before each upload.
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

`social_accounts` (see `knowledge-base/workspaces-accounts/README.md`). TikTok rows: `account_type = profile`, `provider_account_id` = `open_id` (unique per app and user), `handle` = username, `refresh_token` set, `token_expires_at` about 2 hours ahead and moved forward on each refresh. No new columns.

## Rules

- **Redirect URI must be https and registered in Login Kit exactly** (no `http://localhost`, no query string). Use Herd's `https://postloom.test/accounts/tiktok/callback` (`herd secure postloom`). If TikTok refuses that domain, use a tunnel (Cloudflare Tunnel / ngrok) and set `TIKTOK_REDIRECT_URI` and `APP_URL` to it.
- The app stays in **Sandbox** (or unaudited production) for personal use: only TikTok accounts added as target users can log in, and unaudited apps can only post **privately** (`SELF_ONLY`) to **private** accounts, at most 5 posting users per 24 hours. "Send to inbox" (piece 17) is the way to post publicly without an audit.
- Never ask for more than the four scopes above.
- No PKCE: TikTok's web Login Kit uses `state` only.

## Logging

Feature `tiktok` for the connect flow; token messages run on the caller's trace (`accounts` in the daily check, `publishing` in piece 17).

| Message                                                      | Level   | Context                                                |
| ------------------------------------------------------------ | ------- | ------------------------------------------------------ |
| `Redirecting to TikTok to connect an account`                | info    | `workspace_id`, `scopes`, `redirect_uri`               |
| `TikTok callback received`                                   | info    | `workspace_id`                                         |
| `TikTok connection cancelled`                                | info    | `error`, `error_description`                           |
| `TikTok callback state did not match`                        | warning |                                                        |
| `TikTok code exchange failed`                                | warning | `status`, `error`, `error_description`, `log_id`       |
| `TikTok did not grant a video permission`                    | warning | `granted_scopes`                                       |
| `TikTok user info lookup failed`                             | warning | `status`, `error_code`, `message`, `log_id`            |
| `TikTok user info had no open_id`                            | warning |                                                        |
| `TikTok account connected`                                   | info    | `account_id`, `granted_scopes`                         |
| `TikTok connection failed`                                   | error   | exception                                              |
| `TikTok token refreshed`                                     | info    | `account_id`, `token_expires_at`, `refresh_expires_in` |
| `TikTok access was revoked or expired, marked for reconnect` | warning | `account_id`, `reason`                                 |
| `TikTok refused to refresh a token`                          | warning | `account_id`, `status`, `error`, `log_id`              |
| `TikTok could not be reached to refresh a token`             | warning | `account_id`, exception                                |
| `TikTok token check failed, will try again tomorrow`         | warning | `account_id`, exception                                |

`log_id` is TikTok's request id; quote it to TikTok support. Tokens and codes are never logged.

## Config (`.env`)

- `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` (Login Kit app keys), `TIKTOK_REDIRECT_URI=https://postloom.test/accounts/tiktok/callback` (`config/services.php` → `tiktok`).
- `TIKTOK_AUTHORIZE_URL`, `TIKTOK_API_BASE_URL`: defaults are TikTok's; only change for tests.

## Tests

- `tests/Feature/TikTok/TikTokConnectTest.php`: redirect URL (client key, scopes, redirect URI, state in session), missing keys, connect into the starting workspace, reconnect updates the same account, forged state, cancel, missing video scope, refused code exchange, provider availability.
- `tests/Feature/TikTok/TikTokTokenServiceTest.php`: refresh saves the new refresh token, valid token reused, `invalid_grant` marks reconnect, 5xx is retryable, the daily check refreshes TikTok and counts a dead login.

## Known limits

- Connect only; publishing comes in piece 17.
- TikTok's token, refresh and revoke reference pages could not be read during research (403); field names follow the OAuth guide and TikTok's v2 conventions. Check `log_id` and `error` in the logs if a real connect fails.
- Disconnecting in Postloom does not revoke the token at TikTok; remove the app in TikTok → Settings → Security → Manage app permissions.
