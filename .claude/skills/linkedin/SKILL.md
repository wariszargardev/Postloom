---
name: linkedin
description: Postloom LinkedIn integration — use when setting up the LinkedIn developer app, changing the Connect / Reconnect flow, the linkedin-openid Socialite driver, scopes (w_member_social), the OAuth callback, or the daily accounts:check-tokens command. Also for "Connect LinkedIn is disabled", "LinkedIn connection failed / cancelled / expired", "redirect_uri does not match", "LinkedIn did not allow posting". Also for publishing to LinkedIn: LinkedInPublisher, LinkedInApiClient, LinkedInMediaUploader (image, document, multi-part video), LinkedInCommentaryFormatter (escaping, hashtags), AudioToVideoConverter, the Linkedin-Version header, and errors like "LinkedIn rejected the access token", "LinkedIn refused (403)", "rate limit (429)", "could not process the video", or a post that failed or shows odd characters on LinkedIn. Later for company pages.
---

# LinkedIn

KB: `knowledge-base/linkedin/README.md` (includes the LinkedIn app setup guide).

## Key files

- `app/Services/LinkedIn/LinkedInConnectService.php`
- `app/Http/Controllers/LinkedInConnectController.php`
- `app/Exceptions/LinkedInScopeMissingException.php`
- `app/Console/Commands/CheckAccountTokens.php`, schedule in `routes/console.php`
- `config/services.php` (`linkedin-openid`), `config/postloom.php` (`timezone`)
- Publishing: `app/Services/LinkedIn/LinkedInPublisher.php`, `LinkedInApiClient.php`, `LinkedInApiException.php`, `LinkedInMediaUploader.php`, `LinkedInCommentaryFormatter.php`; `app/Services/Media/AudioToVideoConverter.php`; registered in `AppServiceProvider`

## Debugging

| Symptom                                           | Check                                                                                                                                                                                                                                                       |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Connect button disabled                           | `LINKEDIN_CLIENT_ID` / `LINKEDIN_CLIENT_SECRET` empty, or config cached: `php artisan config:clear`                                                                                                                                                         |
| LinkedIn shows "redirect_uri does not match"      | `LINKEDIN_REDIRECT_URI` must equal the URL in the app's Auth tab exactly (`http://localhost:8000/accounts/linkedin/callback`)                                                                                                                               |
| "LinkedIn did not allow posting"                  | Check the log line `LinkedIn did not grant the posting permission` → `approved_scopes`. If `w_member_social` is really missing, add the **Share on LinkedIn** product and connect again. If it is there, the scope parsing (`normalizeScopes()`) is broken. |
| "The LinkedIn login expired"                      | Session lost between redirect and callback (for example opened on 127.0.0.1 instead of localhost); start again from Accounts                                                                                                                                |
| Account shows Reconnect needed                    | Token past ~60 days; click Reconnect                                                                                                                                                                                                                        |
| Post failed: "LinkedIn rejected the access token" | A 401 from LinkedIn; the account is now marked Reconnect needed. Reconnect, then Retry failed accounts                                                                                                                                                      |
| Post failed: "LinkedIn refused … (403)"           | App lost the **Share on LinkedIn** product or `w_member_social`; re-add it and reconnect                                                                                                                                                                    |
| Post failed with "(400)" / "(422)"                | LinkedIn rejected the content; the message after the colon is LinkedIn's. Common causes: `LINKEDIN_API_VERSION` too old (bump it, versions live about a year), image too large (over 36 M pixels)                                                           |
| "rate limit reached (429)" / "(5xx)"              | Temporary; the job retries after 1 then 5 minutes                                                                                                                                                                                                           |
| "could not process the video"                     | LinkedIn's `PROCESSING_FAILED`: length must be 3 s–30 min, MP4, 75 KB–500 MB                                                                                                                                                                                |
| "still processing the video after 300 seconds"    | Big video; it retries (and uploads again). Raise `LINKEDIN_VIDEO_WAIT_SECONDS` if it happens often                                                                                                                                                          |
| Audio post failed: "ffmpeg failed"                | `FFMPEG_PATH` wrong or ffmpeg missing; see the `Audio could not be turned into a video` log line                                                                                                                                                            |
| Odd backslashes or broken text on LinkedIn        | Check `LinkedInCommentaryFormatter` (reserved characters and hashtags)                                                                                                                                                                                      |

Publishing logs run under `extra.feature` = `publishing` on the job's `trace_id` (`LinkedIn returned an error` has `status`, `service_error_code`, `linkedin_message`).

Logs: in `storage/logs/postloom-*.log` filter `extra.feature` = `linkedin`, then follow the `trace_id`. Errors carry `exception_class` and `exception_message`.
