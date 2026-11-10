---
name: linkedin
description: Postloom LinkedIn integration — use when setting up the LinkedIn developer app, changing the Connect / Reconnect flow, the linkedin-openid Socialite driver, scopes (w_member_social), the OAuth callback, or the daily accounts:check-tokens command. Also for "Connect LinkedIn is disabled", "LinkedIn connection failed / cancelled / expired", "redirect_uri does not match", "LinkedIn did not allow posting", and later for publishing to LinkedIn (piece 08) and company pages.
---

# LinkedIn

KB: `knowledge-base/linkedin/README.md` (includes the LinkedIn app setup guide).

## Key files

- `app/Services/LinkedIn/LinkedInConnectService.php`
- `app/Http/Controllers/LinkedInConnectController.php`
- `app/Exceptions/LinkedInScopeMissingException.php`
- `app/Console/Commands/CheckAccountTokens.php`, schedule in `routes/console.php`
- `config/services.php` (`linkedin-openid`), `config/postloom.php` (`timezone`)

## Debugging

| Symptom                                      | Check                                                                                                                                                                                                                                                       |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Connect button disabled                      | `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET` empty, or config cached: `php artisan config:clear`                                                                                                                                                         |
| LinkedIn shows "redirect_uri does not match" | `LINKEDIN_REDIRECT_URI` must equal the URL in the app's Auth tab exactly (`http://localhost:8000/accounts/linkedin/callback`)                                                                                                                               |
| "LinkedIn did not allow posting"             | Check the log line `LinkedIn did not grant the posting permission` → `approved_scopes`. If `w_member_social` is really missing, add the **Share on LinkedIn** product and connect again. If it is there, the scope parsing (`normalizeScopes()`) is broken. |
| "The LinkedIn login expired"                 | Session lost between redirect and callback (for example opened on 127.0.0.1 instead of localhost); start again from Accounts                                                                                                                                |
| Account shows Reconnect needed               | Token past ~60 days; click Reconnect                                                                                                                                                                                                                        |

Logs: in `storage/logs/postloom-*.log` filter `extra.feature` = `linkedin`, then follow the `trace_id`. Errors carry `exception_class` and `exception_message`.
