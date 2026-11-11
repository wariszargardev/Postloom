---
name: tiktok
description: Postloom TikTok integration — use when setting up the TikTok developer app (Login Kit, Content Posting API, sandbox, target users), changing the TikTok Connect or Reconnect flow, scopes (user.info.basic, user.info.profile, video.upload, video.publish), the state check, the /v2/oauth/token/ code exchange, /v2/user/info/, multiple TikTok accounts per workspace, or token renewal (TikTokTokenService, rotating refresh tokens, invalid_grant, the daily accounts:check-tokens refresh). Also for "Connect TikTok is disabled", "redirect_uri is not registered", "TikTok login expired", "did not allow posting videos", "TikTok did not accept the login", "Could not connect TikTok", or a TikTok account showing Reconnect needed. Publishing videos (piece 17) will be added here.
---

# TikTok

KB: `knowledge-base/tiktok/README.md`. Setup guide: `docs/guides/tiktok/README.md`.

## Key files

- `app/Services/TikTok/TikTokConnectService.php`, `TikTokTokenService.php`, `TikTokTokenException.php`
- `app/Http/Controllers/TikTokConnectController.php`, routes `accounts.tiktok.*` in `routes/web.php`
- `app/Exceptions/TikTokConnectException.php`
- `app/Console/Commands/CheckAccountTokens.php` (daily refresh)
- `app/Enums/SocialProvider.php`, `config/services.php` (`tiktok`), `database/factories/SocialAccountFactory.php` (`tikTok()` state)

## Debugging

| Symptom                               | Check                                                                                                                             |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Connect button disabled               | `TIKTOK_CLIENT_KEY` / `TIKTOK_CLIENT_SECRET` empty, or config cached: `php artisan config:clear`                                  |
| TikTok shows a redirect_uri error     | Login Kit's Redirect URI must equal `TIKTOK_REDIRECT_URI` exactly and be https; open Postloom on that same https host             |
| TikTok says the account can't log in  | App in Sandbox and that TikTok account isn't a target user: add it under Sandbox settings                                         |
| "The TikTok login expired"            | `state` mismatch: the session was lost (different host than `APP_URL`, cookies blocked, or callback opened twice). Connect again  |
| "did not allow posting videos"        | `video.publish` / `video.upload` not granted: add Content Posting API to the app and tick every permission                        |
| "TikTok did not accept the login (…)" | Log `TikTok code exchange failed`: `invalid_client` = wrong keys, `invalid_grant` = code reused or redirect URI differs           |
| "Could not connect TikTok"            | Log `TikTok user info lookup failed`: `error_code` `scope_not_authorized` = `user.info.profile` missing in the app's scopes       |
| Account shows Reconnect needed        | `TikTok access was revoked or expired` with `reason` `invalid_grant`: app removed in TikTok, or refresh token older than 180 days |
| Every account fails to refresh        | `TikTok refused to refresh a token` with `error` `invalid_client`: the `.env` keys don't match the app                            |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `tiktok` (connect) or `accounts` (daily check), then follow the `trace_id`. TikTok's `log_id` is in the warning context.
