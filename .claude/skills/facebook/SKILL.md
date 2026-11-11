---
name: facebook
description: Postloom Facebook integration — use when setting up the Meta app, changing the Facebook Connect or Reconnect flow, the facebook Socialite driver, the Page permissions (pages_show_list, pages_read_engagement, pages_manage_posts), the long-lived token exchange, /me/accounts Page tokens, the Graph API version, the daily Page token check, or Graph error mapping (190, 200, 368, rate limits). Also for "Connect Facebook is disabled", "URL blocked", "did not allow posting", "No Facebook Page was shared", "Could not connect Facebook", a Page showing Reconnect needed, and later for posting to Facebook Pages (piece 14).
---

# Facebook

KB: `knowledge-base/facebook/README.md`. Setup guide: `docs/guides/facebook/README.md`.

## Key files

- `app/Services/Facebook/FacebookConnectService.php`, `FacebookGraphClient.php`, `FacebookApiException.php`
- `app/Http/Controllers/FacebookConnectController.php`, routes `accounts.facebook.*` in `routes/web.php`
- `app/Exceptions/FacebookConnectException.php`
- `app/Console/Commands/CheckAccountTokens.php` (Page token check)
- `config/services.php` (`facebook`), `resources/js/components/PlatformIcon.vue`

## Debugging

| Symptom                                 | Check                                                                                                   |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| Connect button disabled                 | `FACEBOOK_CLIENT_ID` / `FACEBOOK_CLIENT_SECRET` empty, or config cached: `php artisan config:clear`     |
| Facebook shows "URL blocked"            | Valid OAuth Redirect URIs must contain `FACEBOOK_REDIRECT_URI` exactly                                  |
| "Invalid Scopes" on the Facebook screen | A permission isn't added to the app's use case (guide step 3)                                           |
| "did not allow posting"                 | Log `Facebook did not grant the posting permission` → `granted`; reconnect and keep "manage content" on |
| "No Facebook Page was shared"           | No Page ticked, or the role on the Page lacks `CREATE_CONTENT`                                          |
| "Could not connect Facebook"            | Log `Facebook returned an error`: `code 1` + "client_secret" = wrong `.env` secret                      |
| Page shows Reconnect needed             | Log `Facebook Page login was revoked` (190, subcode 460 = password changed, 458 = app removed)          |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `facebook` (connect) or `accounts` (daily check), then follow the `trace_id`.
