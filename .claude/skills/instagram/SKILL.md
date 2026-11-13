---
name: instagram
description: Postloom Instagram integration — use when connecting Instagram professional accounts through the Meta app (Facebook Login, instagram_basic, instagram_content_publish, Pages with instagram_business_account), changing InstagramConnectService, InstagramPublisher, InstagramPostRules (post, carousel, Reel, Story), the container flow (media, media_publish, status_code polling), resumable upload via rupload.facebook.com, or the public media link (PublicMediaUrl, signed public-media route, RestrictTunnelRequests, the Cloudflare tunnel tab). Also for errors like "No Instagram professional account was found", "did not allow publishing to Instagram", a photo post going to To post / Ready for you instead of publishing, "could not process the media (ERROR", "still processing", 9007, daily limit, "may already be posted", or a missing tunnel.
---

# Instagram

KB: `knowledge-base/instagram/README.md`. Setup guide: `docs/guides/instagram/README.md`. Meta app basics: `facebook` skill.

## Key files

- `app/Services/Instagram/InstagramConnectService.php`, `InstagramPublisher.php`, `PublicMediaUrl.php`
- `app/Http/Controllers/InstagramConnectController.php`, `PublicMediaController.php`, routes `accounts.instagram.*`, `public-media.show`
- `app/Http/Middleware/RestrictTunnelRequests.php` (global, in `bootstrap/app.php`)
- `app/Services/Posts/Rules/InstagramPostRules.php`, `app/Services/Media/JpegConverter.php`
- `app/Services/Facebook/FacebookGraphClient.php` (shared Graph calls, `uploadBinary()`, error mapping)
- Hand-off: `app/Services/Publishing/ManualHandoffService.php` (`DESTINATIONS`), `ToPostController`, `pages/to-post/Index.vue`
- `start.ps1` (tunnel tab), `stop.ps1`, `config/services.php` (`instagram`)

## Debugging

| Symptom                                                | Check                                                                                                                                                                                                                                                  |
| ------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Connect disabled                                       | `FACEBOOK_CLIENT_ID` / `FACEBOOK_CLIENT_SECRET` empty, or `php artisan config:clear`                                                                                                                                                                   |
| "Invalid Scopes" on Facebook                           | `instagram_basic` / `instagram_content_publish` not added to the Meta app's use case (guide step 3)                                                                                                                                                    |
| "URL blocked"                                          | `http://localhost:8000/accounts/instagram/callback` missing from Valid OAuth Redirect URIs                                                                                                                                                             |
| "did not allow publishing to Instagram"                | Log `Facebook did not grant the Instagram publishing permission` → `granted`                                                                                                                                                                           |
| "No Instagram professional account was found"          | Log `Facebook returned Pages for Instagram`: `instagram_id` null on every Page = the IG account is personal, not linked to that Page, or not ticked. Fix in Instagram / Page settings, then Connect → "Edit previous settings" and tick the IG account |
| Photo post is Ready for you                            | Log `No public address…` / `…could not be reached`: tunnel tab not running, `cloudflared` missing, or `tunnel-url.txt` empty                                                                                                                           |
| Instagram can't fetch the photo (error 9004 / 2207052) | Open the `image_url` from the log context host + `/up` in a browser; the link expires after 15 min                                                                                                                                                     |
| `could not process the media (ERROR`                   | Video codec, length (Reel 3 s–15 min, Story ≤60 s) or size; Instagram's `status` text is in the message                                                                                                                                                |
| Resumable upload refused                               | Set `INSTAGRAM_VIDEO_UPLOAD=url` (videos then use the tunnel too)                                                                                                                                                                                      |
| `limit of N API posts`                                 | `content_publishing_limit` used up; reschedule after 24 h                                                                                                                                                                                              |
| `may already be posted`                                | Connection dropped at `media_publish`; check the profile before Retry                                                                                                                                                                                  |
| Reconnect needed                                       | Page token revoked (190); reconnect Instagram (and Facebook if the Page also shows it)                                                                                                                                                                 |
| Tunnel shows Postloom pages                            | Must never happen: `RestrictTunnelRequests` returns 404 for everything but `public-media/*` and `up`                                                                                                                                                   |

Logs: `storage/logs/postloom-*.log`, filter `extra.feature` = `instagram` (connect) or `publishing` (posts, public links), then follow the `trace_id`.
