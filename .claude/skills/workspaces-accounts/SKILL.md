---
name: workspaces-accounts
description: Postloom workspaces and connected social accounts — use when changing the workspace switcher, the Workspaces or Accounts pages, the workspaces / social_accounts tables, token storage or expiry status, or when wiring a new platform's OAuth callback (call SocialAccountService::saveConnectedAccount). Also for "account shows Reconnect needed / Expires soon", "can't delete workspace", "account missing from Accounts page", or adding a SocialProvider case.
---

# Workspaces and social accounts

KB: `knowledge-base/workspaces-accounts/README.md`.

## Key files

- `app/Models/Workspace.php`, `app/Models/SocialAccount.php` (`status()`, `isTokenExpired()`, `expiresSoon()`)
- `app/Enums/SocialProvider.php` (`label()`, `isAvailable()`, `availabilityNote()`, `connectRouteName()`), `SocialAccountType.php`, `SocialAccountStatus.php`
- `app/Services/WorkspaceService.php`, `app/Services/SocialAccountService.php`
- `app/Http/Middleware/EnsureCurrentWorkspace.php`
- `app/Http/Controllers/WorkspaceController.php`, `SocialAccountController.php`, policies in `app/Policies`
- `resources/js/pages/workspaces/Index.vue`, `resources/js/pages/accounts/Index.vue`, `resources/js/components/WorkspaceSwitcher.vue`

## Adding a platform (pieces 03+)

1. Make `SocialProvider::isAvailable()` return true for it (LinkedIn checks its keys are set), update `availabilityNote()`, and return its redirect route from `connectRouteName()`. See the `linkedin` skill as the worked example.
2. In the OAuth callback, call `SocialAccountService::saveConnectedAccount($user->currentWorkspace, [...])` with provider, account type, provider account id, name, tokens and expiry.
3. Point the Accounts page Connect button at the platform's redirect route.

## Debugging

- **Account missing:** it belongs to another workspace; check `social_accounts.workspace_id` against `users.current_workspace_id`.
- **Wrong status:** compare `token_expires_at` and `needs_reconnect` with the rules in the KB.
- **Tokens unreadable** (`DecryptException`): `APP_KEY` changed; reconnect the accounts.
- **Logs:** filter `storage/logs/postloom-*.log` by `extra.feature` = `workspaces` or `accounts`, then by `workspace_id` / `account_id`.
