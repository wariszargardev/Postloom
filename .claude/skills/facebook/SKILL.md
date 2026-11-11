---
name: facebook
description: Postloom Facebook integration — use when setting up the Meta app, changing the Facebook Connect or Reconnect flow, the facebook Socialite driver, the Page permissions (pages_show_list, pages_read_engagement, pages_manage_posts), the long-lived token exchange, /me/accounts Page tokens, the Graph API version, the daily Page token check, or Graph error mapping (190, 200, 368, rate limits). Also for "Connect Facebook is disabled", "URL blocked", "did not allow posting", "No Facebook Page was shared", "Could not connect Facebook", a Page showing Reconnect needed. Also for posting to Pages: FacebookPublisher, FacebookPostRules, photo/multi-photo/video uploads, Reels (video_reels, rupload), permalinks, and errors like "can't post PDFs", "Reel needs a vertical 9:16 video", WebP or 10 MB photo refusals, or a post missing on the Page.
---

# Facebook

KB: `knowledge-base/facebook/README.md`. Setup guide: `docs/guides/facebook/README.md`.

## Key files

- `app/Services/Facebook/FacebookConnectService.php`, `FacebookGraphClient.php`, `FacebookApiException.php`
- `app/Http/Controllers/FacebookConnectController.php`, routes `accounts.facebook.*` in `routes/web.php`
- `app/Exceptions/FacebookConnectException.php`
- `app/Console/Commands/CheckAccountTokens.php` (Page token check)
- `config/services.php` (`facebook`), `resources/js/components/PlatformIcon.vue`
- Publishing: `app/Services/Facebook/FacebookPublisher.php`, `app/Services/Posts/Rules/FacebookPostRules.php`, `FacebookGraphClient::upload()` / `uploadBinary()`

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
| Post failed with code 200 / 10          | The Page token lost `pages_manage_posts`; reconnect the Page and keep every permission on               |
| Post failed with code 368               | Facebook flagged the content as possibly abusive; wait, change the content, don't retry blindly         |
| Code 32 / 80001 / 4 / 17 / 613          | Rate limit; the job retries after 1 then 5 minutes                                                      |
| Reel failed (1363040–1363129)           | Aspect, resolution, length or frame rate: vertical 9:16, 540×960 or more, 3–90 s, 24–60 fps             |
| View post opens a missing page          | Reels and videos are still processing for a minute; otherwise check the Page's posts                    |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `facebook` (connect), `accounts` (daily check) or `publishing` (posts), then follow the `trace_id`.
