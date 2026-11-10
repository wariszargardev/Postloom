---
name: youtube
description: Postloom YouTube integration — use when setting up the Google Cloud project / OAuth client, changing the YouTube Connect or Reconnect flow, the google Socialite driver, scopes (youtube.upload, youtube.readonly), the channels.list lookup, multiple channels per workspace, or token renewal (YouTubeTokenService, refresh tokens, invalid_grant, the daily accounts:check-tokens refresh). Also for "Connect YouTube is disabled", "Google did not allow uploading", "no refresh token", "no YouTube channel", "Could not connect YouTube", "Google hasn't verified this app", logins dying after 7 days, and later for uploading videos to YouTube (piece 11).
---

# YouTube

KB: `knowledge-base/youtube/README.md`. Setup guide: `docs/guides/youtube/README.md`.

## Key files

- `app/Services/YouTube/YouTubeConnectService.php`, `YouTubeTokenService.php`, `YouTubeTokenException.php`
- `app/Http/Controllers/YouTubeConnectController.php`, routes `accounts.youtube.*` in `routes/web.php`
- `app/Exceptions/YouTubeConnectException.php`
- `app/Console/Commands/CheckAccountTokens.php` (daily refresh check)
- `app/Models/SocialAccount.php` (`canRefreshToken()`), `config/services.php` (`google`)

## Debugging

| Symptom                              | Check                                                                                                                                     |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Connect button disabled              | `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` empty, or config cached: `php artisan config:clear`                                           |
| Google shows `redirect_uri_mismatch` | The OAuth client's Authorized redirect URI must equal `YOUTUBE_REDIRECT_URI` exactly                                                      |
| Google shows `access_blocked` / 403  | App in Testing mode and your Google account isn't a test user: add it, or publish the app to In production                                |
| "Google did not allow uploading"     | The upload checkbox was unticked on the Google screen; connect again and tick it                                                          |
| "did not return a refresh token"     | Remove Postloom at myaccount.google.com/permissions, then connect again                                                                   |
| "no YouTube channel"                 | That Google login (or brand account) has no channel; create one or pick another                                                           |
| "Could not connect YouTube"          | Log line `YouTube channel lookup failed`: `reason` `accessNotConfigured` = YouTube Data API v3 not enabled in the Cloud project           |
| Channel shows Reconnect needed       | `YouTube access was revoked or expired` with `reason` `invalid_grant`: revoked, password changed, or Testing-mode 7-day expiry; Reconnect |
| Every channel fails to refresh       | `Google refused to refresh` with `error` `invalid_client`: the `.env` keys don't match the Cloud project                                  |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `youtube` (connect) or `accounts` (daily check), then follow the `trace_id`.
