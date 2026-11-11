# Facebook

## Purpose

Connect one or more **Facebook Pages** to a workspace (piece 13). Each Page is its own account. Posting to Pages comes in piece 14 (`feat/14-facebook-publish`). Facebook doesn't allow apps to post to personal profiles, so only Pages are supported.

Setup guide for the Meta app: `docs/guides/facebook/README.md`.

## Flow

1. **Accounts → Facebook → Connect** opens `GET /accounts/facebook/redirect`. The current workspace id goes into the session (`facebook_connect_workspace_id`). Socialite's `facebook` driver (Graph version `FACEBOOK_GRAPH_VERSION`, fields `name` only) sends the browser to `https://www.facebook.com/v27.0/dialog/oauth` with **only** `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` (`setScopes`, so Socialite's default `email` is not requested).
2. The owner continues, ticks the Pages to share and saves. Facebook redirects to `GET /accounts/facebook/callback?code=…&state=…`.
3. The callback checks `state` and swaps the code for a short-lived user token (1–2 hours). Then `FacebookGraphClient`:
    - `GET /oauth/access_token?grant_type=fb_exchange_token…` → long-lived user token (about 60 days).
    - `GET /me/permissions` → refused if `pages_manage_posts` isn't granted.
    - `GET /me/accounts?fields=id,name,username,access_token,tasks,picture{url}&limit=100` → the shared Pages, each with its own **Page token**.
4. Pages without the `CREATE_CONTENT` task (for example analyst roles) are skipped. None left → "No Facebook Page was shared".
5. Each Page is saved with `SocialAccountService::saveConnectedAccount()`: provider `facebook`, type `page`, `provider_account_id` = Page id, `name`, `handle` = Page username, avatar = picture URL, `access_token` = Page token (encrypted), `refresh_token` and `token_expires_at` = null, `scopes` = granted permissions.
6. Back to Accounts: "Build Real Code and Side Project are connected." Connecting again updates the same Pages.

### Token life

A Page token from a long-lived user token **doesn't expire** on a timer (Meta docs). It stops working when the owner changes their Facebook password, removes the app, or loses their role on the Page. The daily `accounts:check-tokens` calls `GET /me?fields=id` with each Page token; error **190** marks the Page `needs_reconnect` (banner + Reconnect button). Other errors (rate limits, outages) only log a warning.

## Entry points

| Route                             | Name                         | Action                               |
| --------------------------------- | ---------------------------- | ------------------------------------ |
| `GET /accounts/facebook/redirect` | `accounts.facebook.redirect` | `FacebookConnectController@redirect` |
| `GET /accounts/facebook/callback` | `accounts.facebook.callback` | `FacebookConnectController@callback` |

- `app/Services/Facebook/FacebookConnectService.php`: `isConfigured()`, `redirect()`, `completeConnection()` (returns the saved accounts), `checkPageToken()`; constants `DRIVER`, `SCOPES`, `POST_SCOPE`, `CREATE_CONTENT_TASK`.
- `app/Services/Facebook/FacebookGraphClient.php`: `url()`, `get()`, `post()`, `longLivedUserToken()`, error mapping (also used by the publisher in piece 14).
- `app/Services/Facebook/FacebookApiException.php`: a `PublishException` with `statusCode`, `errorCode`, `errorSubcode`, `isInvalidToken()`.
- `app/Exceptions/FacebookConnectException.php`: `postingPermissionMissing()`, `noPages()`.
- `app/Console/Commands/CheckAccountTokens.php`: the Page token check.

## Data

`social_accounts` rows with `provider = facebook`, `account_type = page`, Page token in `access_token`, no refresh token, no expiry date. The Accounts page shows "Token: doesn't expire".

## Rules

- Pages only; never request `email` or user-profile permissions.
- The Meta app stays in **Development mode**; app admins/developers/testers can use the Page permissions on Pages they manage without App Review. Serving other people's Pages would need App Review, Advanced Access and business verification.
- Graph error mapping (`FacebookGraphClient`): **190** → invalid login (reconnect); **4, 17, 32, 613, 80001** → rate limit (retryable); **10, 200** → permission (permanent); **368** → blocked as abusive (permanent, no blind retries); 5xx → retryable; other 4xx → permanent with Facebook's message.

## Logging

Feature `facebook` for the connect flow; the daily check runs on the `accounts` trace.

| Message                                                                | Level   | Context                                                   |
| ---------------------------------------------------------------------- | ------- | --------------------------------------------------------- |
| `Redirecting to Facebook to connect Pages`                             | info    | `workspace_id`, `scopes`                                  |
| `Facebook callback received`                                           | info    | `workspace_id`                                            |
| `Facebook did not grant the posting permission`                        | warning | `granted`                                                 |
| `Facebook shared no Page the owner can post to`                        | warning |                                                           |
| `Social account connected` / `reconnected`                             | info    | `provider`, `account_id`, `account_type`                  |
| `Facebook Pages connected`                                             | info    | `account_ids`, `page_ids`                                 |
| `Facebook connection cancelled`                                        | info    | `error`                                                   |
| `Facebook callback state did not match` / `Facebook connection failed` | error   | exception                                                 |
| `Facebook returned an error`                                           | warning | `action`, `status`, `code`, `subcode`, `facebook_message` |
| `Facebook could not be reached`                                        | warning | `action`, exception                                       |
| `Facebook Page login was revoked, marked for reconnect`                | warning | `account_id`, `subcode`                                   |
| `Facebook Page token check failed, will try again tomorrow`            | warning | `account_id`, exception                                   |

Tokens are never logged.

## Config (`.env`)

- `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET` (App ID and App secret), `FACEBOOK_REDIRECT_URI=http://localhost:8000/accounts/facebook/callback` (`config/services.php` → `facebook`)
- `FACEBOOK_GRAPH_VERSION=v27.0` (bump when Meta retires it; each version lives about two years), `FACEBOOK_GRAPH_BASE_URL` (default `https://graph.facebook.com`)

## Tests

- `tests/Feature/Facebook/FacebookConnectTest.php`: redirect with only the Page scopes; every shared Page saved with its token; reconnect updates in place; Pages without CREATE_CONTENT skipped; posting permission switched off; no Page shared; token exchange error; cancel; expired state; disabled without keys; daily check marks a 190 Page for reconnect; rate limit during the check keeps the Page connected.

Facebook is faked with a mocked Socialite provider and `Http::fake()`.

## Known limits

- Disconnect in Postloom doesn't remove the app from Facebook; do that at facebook.com → Settings → Business integrations.
- Pages shared in one Connect all land in the current workspace; disconnect the ones you don't want there.
- Instagram (which uses the same Meta app) comes later and needs a public media link.
