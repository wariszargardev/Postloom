# LinkedIn

## Purpose

Connect one or more **personal LinkedIn profiles** to a workspace, so later pieces can post to them. This piece covers the connection (OAuth) only. Publishing comes in piece 08, company pages in `feat/linkedin-pages`.

## LinkedIn app setup (one time)

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
4. If LinkedIn reports approved scopes and `w_member_social` is missing, the connection is refused (`LinkedInScopeMissingException`).
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
- `POSTLOOM_TIMEZONE=Asia/Karachi` (`config/postloom.php`): timezone of the 09:00 token check

## Tests

- `tests/Feature/LinkedIn/LinkedInConnectTest.php`: redirect URL and scopes, saves into the starting workspace, reconnect updates in place, cancel, missing posting scope, expired state, disabled without keys, connect link with keys, guests blocked.
- `tests/Feature/Accounts/AccountTokenAttentionTest.php`: daily command marks expired accounts, command is scheduled, banner count across the owner's workspaces only.

LinkedIn is mocked in tests (`Socialite::shouldReceive('driver')`); no real calls.

## Known limits

- The redirect URL must match exactly. Running on another host or port needs that URL added in the LinkedIn app and in `LINKEDIN_REDIRECT_URI`.
- Disconnect in Postloom does not revoke the app on LinkedIn. Do that in LinkedIn → Settings → Data privacy → Permitted services.
- The daily check only runs while `start.ps1` (scheduler) is running.
