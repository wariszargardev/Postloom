---
name: tiktok
description: Postloom TikTok integration — use when setting up the TikTok developer app (Login Kit, Content Posting API, sandbox, target users), changing TikTok Connect / Reconnect, scopes, the state check, token renewal (TikTokTokenService, rotating refresh tokens, the daily refresh), or posting videos (TikTokPublisher, TikTokUploader chunks and status polling, TikTokApiClient, TikTokPostRules inbox vs direct, privacy, creator info). Also for errors like non_sandbox_target, redirect_uri, "TikTok login expired", "did not allow posting videos", Reconnect needed, unaudited_client_can_only_post_to_private_accounts, spam_risk_too_many_posts, privacy_level_option_mismatch, status FAILED, or a video missing from the TikTok inbox.
---

# TikTok

KB: `knowledge-base/tiktok/README.md`. Setup guide: `docs/guides/tiktok/README.md`.

## Key files

- `app/Services/TikTok/TikTokConnectService.php`, `TikTokTokenService.php`, `TikTokTokenException.php`
- `app/Http/Controllers/TikTokConnectController.php`, routes `accounts.tiktok.*` in `routes/web.php`
- `app/Exceptions/TikTokConnectException.php`
- `app/Console/Commands/CheckAccountTokens.php` (daily refresh)
- `app/Enums/SocialProvider.php`, `config/services.php` (`tiktok`), `database/factories/SocialAccountFactory.php` (`tikTok()` state)
- Publishing: `app/Services/TikTok/TikTokPublisher.php`, `TikTokUploader.php`, `TikTokApiClient.php`, `TikTokApiException.php`; `app/Services/Posts/Rules/TikTokPostRules.php`; TikTok card in `resources/js/components/posts/PostPreview.vue`

## Debugging

| Symptom                               | Check                                                                                                                             |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Connect button disabled               | `TIKTOK_CLIENT_KEY` / `TIKTOK_CLIENT_SECRET` empty, or config cached: `php artisan config:clear`                                  |
| TikTok shows a redirect_uri error     | Login Kit's Redirect URI must equal `TIKTOK_REDIRECT_URI` exactly and be https; open Postloom on that same https host             |
| TikTok page "non_sandbox_target"      | The TikTok account used isn't a Sandbox target user: log in as a listed one, or add it under Sandbox settings → Target Users      |
| "The TikTok login expired"            | `state` mismatch: the session was lost (different host than `APP_URL`, cookies blocked, or callback opened twice). Connect again  |
| "did not allow posting videos"        | `video.publish` / `video.upload` not granted: add Content Posting API to the app and tick every permission                        |
| "TikTok did not accept the login (…)" | Log `TikTok code exchange failed`: `invalid_client` = wrong keys, `invalid_grant` = code reused or redirect URI differs           |
| "Could not connect TikTok"            | Log `TikTok user info lookup failed`: `error_code` `scope_not_authorized` = `user.info.profile` missing in the app's scopes       |
| Account shows Reconnect needed        | `TikTok access was revoked or expired` with `reason` `invalid_grant`: app removed in TikTok, or refresh token older than 180 days |
| Every account fails to refresh        | `TikTok refused to refresh a token` with `error` `invalid_client`: the `.env` keys don't match the app                            |

| Post failed: "only allows direct posts to a private account" | `unaudited_client_can_only_post_to_private_accounts`: set the TikTok account private, or use "Send to TikTok inbox" |
| Post failed: "doesn't allow … Allowed: Only me" | Creator info says that privacy isn't allowed (unaudited app): pick Only me, or use inbox |
| Post failed: "daily post limit" / "limit of accounts" | `spam_risk_too_many_posts` (~15 a day) / `reached_active_user_cap` (5 users a day): retry tomorrow |
| Post failed: "TikTok could not post the video (…)" | Status `FAILED`; the reason (e.g. `file_format_check_failed`, `duration_check_failed`) says what to fix |
| Published, but nothing in the TikTok inbox | Note says "still processing": TikTok took over `TIKTOK_STATUS_WAIT_SECONDS`; check the app's inbox later |
| Caption missing on an inbox video | Expected: inbox uploads can't carry a caption; copy it from the Delivery note |
| "upload address expired" / chunk errors | Log `TikTok refused an upload chunk` / `chunk lost`; the job retries from the start |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `tiktok` (connect), `publishing` (posts) or `accounts` (daily check), then follow the `trace_id`. TikTok's `log_id` is in the warning context.
