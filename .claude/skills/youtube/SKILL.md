---
name: youtube
description: Postloom YouTube integration — use when setting up the Google Cloud project / OAuth client, changing the YouTube Connect or Reconnect flow, the google Socialite driver, scopes (youtube.upload, youtube.readonly), the channels.list lookup, multiple channels per workspace, or token renewal (YouTubeTokenService, refresh tokens, invalid_grant, the daily accounts:check-tokens refresh). Also for "Connect YouTube is disabled", "Google did not allow uploading", "no refresh token", "no YouTube channel", "Could not connect YouTube", "Google hasn't verified this app", logins dying after 7 days. Also for uploading videos: YouTubePublisher, YouTubeUploader (resumable chunks, 308 Range, resume), YouTubePostRules (title, tags, Short/long, privacy, made for kids, thumbnail), the YouTube settings panel, and errors like quotaExceeded, uploadLimitExceeded, invalidTitle, "thumbnail was refused", or a video stuck or missing on YouTube.
---

# YouTube

KB: `knowledge-base/youtube/README.md`. Setup guide: `docs/guides/youtube/README.md`.

## Key files

- `app/Services/YouTube/YouTubeConnectService.php`, `YouTubeTokenService.php`, `YouTubeTokenException.php`
- `app/Http/Controllers/YouTubeConnectController.php`, routes `accounts.youtube.*` in `routes/web.php`
- `app/Exceptions/YouTubeConnectException.php`
- `app/Console/Commands/CheckAccountTokens.php` (daily refresh check)
- `app/Models/SocialAccount.php` (`canRefreshToken()`), `config/services.php` (`google`)
- Publishing: `app/Services/YouTube/YouTubePublisher.php`, `YouTubeUploader.php`, `YouTubeApiException.php`; `app/Services/Posts/Rules/YouTubePostRules.php`; `resources/js/components/posts/PlatformSettings.vue`, YouTube card in `PostPreview.vue`

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

| Post failed: "upload limit is reached" | `reason` quotaExceeded / uploadLimitExceeded: 100 uploads per day per project; retry after midnight Pacific |
| Post failed: "rejected the video (400, invalidTitle…)" | Fix the title/tags in the YouTube settings, then Retry |
| "upload kept failing" / "session expired" | Network or YouTube trouble mid-upload; the job retries from the start. Check `YouTube upload chunk lost` lines |
| Published, but "thumbnail was refused" | Channel not phone-verified (youtube.com/verify) or image too big; set it in YouTube Studio |
| Video is a Short when it shouldn't be | Format Auto + vertical/square + up to 3 min; pick "Regular video" |
| Published, but "Short cover wasn't set" | YouTube took longer than `YOUTUBE_PROCESSING_WAIT_SECONDS` to process the Short; set the cover in YouTube Studio or raise the wait |
| "video title is the same as the tags" | The title field held the tag list; write a real title (blocked on purpose) |
| Comments / notifications off on YouTube | "Made for kids" was ticked; YouTube turns them off for kids content |
| Video stays private | Privacy setting, or YouTube locked it for an unverified API project: check YouTube Studio |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `youtube` (connect), `accounts` (daily check) or `publishing` (uploads), then follow the `trace_id`.
