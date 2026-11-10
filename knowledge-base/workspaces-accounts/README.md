# Workspaces and social accounts

## Purpose

Workspaces group social accounts (for example "Personal", "Build Real Code"). A workspace can hold any number of accounts per platform: several LinkedIn profiles, YouTube channels, Facebook pages. Later pieces attach posts to a workspace too. This piece stores accounts safely; connecting them (OAuth) starts with LinkedIn in piece 03.

## Flow

1. Every logged-in request runs `EnsureCurrentWorkspace`. If the owner has no workspace, a **Personal** workspace is created and made current. If the current workspace is missing, the oldest one becomes current.
2. The sidebar's workspace switcher lists all workspaces. Choosing one calls `POST /workspaces/{id}/switch` and stores it in `users.current_workspace_id`.
3. **Workspaces page** (`/workspaces`): add (and switch to it), rename, switch, delete. Delete is blocked while the workspace has accounts, and for the last workspace.
4. **Accounts page** (`/accounts`): accounts of the current workspace, grouped by platform, with status badge, type, handle and token expiry; Disconnect; and a "Connect a platform" grid (a platform's Connect button works once its piece ships and its keys are set; LinkedIn since piece 03, YouTube since piece 10).
5. A platform's OAuth callback (piece 03 onward) calls `SocialAccountService::saveConnectedAccount()`. Reconnecting the same account updates the existing row instead of adding a duplicate.

## Entry points

| Route                                 | Name                 | Action                            |
| ------------------------------------- | -------------------- | --------------------------------- |
| `GET /workspaces`                     | `workspaces.index`   | `WorkspaceController@index`       |
| `POST /workspaces`                    | `workspaces.store`   | `WorkspaceController@store`       |
| `PATCH /workspaces/{workspace}`       | `workspaces.update`  | `WorkspaceController@update`      |
| `DELETE /workspaces/{workspace}`      | `workspaces.destroy` | `WorkspaceController@destroy`     |
| `POST /workspaces/{workspace}/switch` | `workspaces.switch`  | `WorkspaceController@switch`      |
| `GET /accounts`                       | `accounts.index`     | `SocialAccountController@index`   |
| `DELETE /accounts/{socialAccount}`    | `accounts.destroy`   | `SocialAccountController@destroy` |

- Services: `app/Services/WorkspaceService.php`, `app/Services/SocialAccountService.php`
- Middleware: `app/Http/Middleware/EnsureCurrentWorkspace.php` (web group, before `HandleInertiaRequests`)
- Policies: `WorkspacePolicy` (update, switch, delete), `SocialAccountPolicy` (delete): only the owner
- Shared prop `workspaces` = `{ current_id, list: [{ id, name }] }` (null for guests)
- Pages: `resources/js/pages/workspaces/Index.vue`, `resources/js/pages/accounts/Index.vue`; component `WorkspaceSwitcher.vue`

## Data

**`workspaces`**: `id`, `user_id`, `name` (unique per user), timestamps.

**`users.current_workspace_id`**: nullable, set to null if that workspace is deleted.

**`social_accounts`**

| Column                          | Notes                                                                                             |
| ------------------------------- | ------------------------------------------------------------------------------------------------- |
| `workspace_id`                  | deleting a workspace deletes its accounts (but the app blocks deleting a workspace with accounts) |
| `provider`                      | `SocialProvider`: linkedin, youtube, facebook, instagram, tiktok, whatsapp                        |
| `account_type`                  | `SocialAccountType`: profile, page, channel                                                       |
| `provider_account_id`           | the platform's id (LinkedIn person id, YouTube channel id…)                                       |
| `name`, `handle`, `avatar_url`  | shown on the Accounts page                                                                        |
| `access_token`, `refresh_token` | **encrypted** with `APP_KEY`, hidden from JSON and page props                                     |
| `token_expires_at`              | drives the status                                                                                 |
| `scopes`                        | JSON list                                                                                         |
| `needs_reconnect`               | set when a platform rejects the token                                                             |
| `connected_at`, `last_used_at`  | last connect / last publish                                                                       |

Unique: `workspace_id + provider + provider_account_id` (the same account can be in two different workspaces, but only once per workspace).

## Rules

- Status (`SocialAccount::status()`): **Reconnect needed** if `needs_reconnect` or the token is expired; **Expires soon** if it expires within 7 days (`EXPIRING_SOON_DAYS`); else **Connected**.
- Accounts with a refresh token (YouTube, `canRefreshToken()`) renew their access token themselves, so its expiry never makes them "Expires soon" or "Reconnect needed"; only `needs_reconnect` does. The Accounts page shows "Token: renews automatically" for them.
- Creating a workspace switches to it.
- Deleting the current workspace switches to the oldest remaining one.
- Changing `APP_KEY` makes every stored token unreadable, so all accounts would need reconnecting.
- Disconnect only deletes the saved token. To revoke the app on LinkedIn's side: LinkedIn → Settings → Data privacy → Permitted services.

## Logging

| Feature      | Message                                                         | Context                                                                      |
| ------------ | --------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `workspaces` | `Default workspace created`                                     | `user_id`, `workspace_id`                                                    |
| `workspaces` | `Workspace created` / `Workspace renamed` / `Workspace deleted` | `workspace_id` (+ `old_name`, `new_name` on rename)                          |
| `workspaces` | `Switched workspace`                                            | `workspace_id`                                                               |
| `workspaces` | `Workspace delete blocked` (warning)                            | `workspace_id`, `reason`                                                     |
| `accounts`   | `Social account connected` / `Social account reconnected`       | `workspace_id`, `provider`, `account_id`, `account_type`, `token_expires_at` |
| `accounts`   | `Social account disconnected`                                   | `workspace_id`, `provider`, `account_id`                                     |

Tokens are never logged.

## Config (`.env`)

- `APP_KEY`: encrypts tokens. Never change it once accounts are connected.

## Tests

- `tests/Feature/Workspaces/WorkspaceManagementTest.php`: default workspace on registration and first visit, create + switch, duplicate name, rename, switch, delete (empty, with accounts, last one), other owner blocked (403), list props.
- `tests/Feature/Accounts/SocialAccountTest.php`: tokens encrypted at rest, tokens never in page props, only current workspace listed, status for valid/expiring/expired/reconnect, save + reconnect updates in place, several accounts per platform, disconnect, other owner blocked.

## Known limits

- Only LinkedIn personal profiles can be connected so far (piece 03). The other platforms stay disabled until their pieces.
- Disconnecting an account with a scheduled or publishing post is blocked (`SocialAccountService::disconnectBlockReason()`); used only in drafts, it is disconnected and removed from them. See `knowledge-base/posts/README.md`.
