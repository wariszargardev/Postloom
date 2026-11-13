# Instagram setup guide (professional accounts)

How to get your Instagram account ready, add Instagram to the Meta app you made for Facebook, connect it to Postloom and turn on automatic photo posts. Takes about 15 minutes, once.

**Video:** [`instagram-setup.mp4`](instagram-setup.mp4). Rebuild it after adding screenshots:

```bash
powershell -File scripts/make-guide-video.ps1 instagram
```

What you need:

- The Meta app and `.env` keys from the [Facebook guide](../facebook/README.md). Instagram uses the same app, so there are no new keys.
- An Instagram account you own, switched to **Business** or **Creator**, and linked to a Facebook Page you manage.
- `cloudflared` on this PC for photo posts (videos don't need it). It's free and needs no Cloudflare account.

---

## 1. Make the Instagram account professional

Instagram app → **Profile → ☰ → Account type and tools → Switch to professional account** → pick **Creator** or **Business**. Personal accounts can't be posted to through the API.

![Professional account](images/01-professional-account.png)

## 2. Link it to your Facebook Page

Facebook → your Page → **Settings → Linked accounts → Instagram → Connect account** (or in Instagram: **Edit profile → Page → Connect**). Postloom finds Instagram accounts through the Pages you share, so an account without a Page is invisible.

![Link to Page](images/02-link-page.png)

## 3. Add the Instagram permissions to the Meta app

<https://developers.facebook.com/apps> → **Postloom → Use cases**. Add (or customize) the Instagram use case, **"Manage messaging & content on Instagram"** with Facebook Login, and click **Add** next to:

- `instagram_basic`
- `instagram_content_publish`

`pages_show_list` and `pages_read_engagement` are already there from the Facebook setup. If Meta also shows `business_management` as required for your Page, add it too.

![Instagram permissions](images/03-instagram-permissions.png)

## 4. Redirect URL

**Facebook Login → Settings → Valid OAuth Redirect URIs** → add this next to the Facebook one:

```
http://localhost:8000/accounts/instagram/callback
```

**Save changes.** `.env` needs nothing new; `INSTAGRAM_REDIRECT_URI` defaults to that address.

![Redirect URL](images/04-redirect-url.png)

## 5. Development mode is enough

Keep **App mode: Development**. You are the app's admin, and the Instagram account belongs to you, so Meta's Standard Access covers publishing without App Review or business verification.

![Development mode](images/05-development-mode.png)

## 6. Restart Postloom

```bash
php artisan config:clear
```

Run `stop.ps1`, then `start.ps1`. A new **Postloom tunnel** tab opens next to web, scheduler, queue and vite (step 8). Accounts → Instagram → **Connect** is now active.

![Connect ready](images/06-accounts-connect-ready.png)

## 7. Connect your Instagram accounts

Click **Connect** → **Continue as …** → tick the **Pages** whose Instagram accounts Postloom may use, and the **Instagram accounts** themselves → keep every permission on → **Save**. Each linked Instagram account becomes its own account (`@handle`) in the current workspace; the token line says **doesn't expire** (it is the Page's token).

![Choose accounts](images/07-choose-accounts.png)

## 8. Photos: the Cloudflare tunnel

Instagram downloads photos from a public web address; it can't take a file upload. `start.ps1` opens a free Cloudflare quick tunnel in the **Postloom tunnel** tab and saves its address (like `https://quiet-river.trycloudflare.com`) to `storage/app/postloom-run/tunnel-url.txt`. When a photo post is due, Postloom gives Instagram a signed link to that one photo, valid for 15 minutes. Anything else through the tunnel answers "Not found", so your Postloom pages stay private.

- Install once if `cloudflared` is missing: <https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/> (Windows `.msi`). `start.ps1` says so when it can't find it.
- The address changes every start; nothing to update by hand.
- If the tunnel is off when a photo post is due, the account becomes **Ready for you** and the post waits under **To post** (download the photos, copy the caption, post it in Instagram, then **Mark as posted**). Or start the tunnel and use **Retry**.

Reels and Story videos are uploaded straight from this PC and work without the tunnel.

![Tunnel tab](images/08-tunnel-tab.png)

## 9. First post

New post → tick the Instagram account → **Format**: Post (1 photo or a carousel of 2–10), Reel (1 video) or Story → schedule it. The preview shows a square feed card, or a vertical card for Reels and Stories. After it goes out, **View post** opens it on Instagram.

![First post](images/09-first-post.png)

---

## If something goes wrong

See `.claude/skills/instagram/SKILL.md` (debug table) and `knowledge-base/instagram/README.md`. Most common:

- **"Invalid Scopes"** on the Facebook screen: step 3 permissions are missing.
- **"URL blocked"**: step 4 redirect URI is missing.
- **"No Instagram professional account was found"**: step 1 or 2 isn't done, or the Instagram account wasn't ticked in step 7. Facebook remembers your earlier Page choices from the Facebook connect and may skip the picker: on the Facebook screen choose **Edit previous settings** and tick the Instagram account. The log line `Facebook returned Pages for Instagram` lists each Page and its `instagram_id` (null = no linked professional account).
- **Photo post Ready for you instead of published**: the tunnel tab isn't running or `cloudflared` isn't installed (step 8).
- **Reel failed with "could not process the media (ERROR"**: check length (3 s to 15 min), size (300 MB) and that it's MP4 or MOV with H.264.

## Screenshots still to add

All of them: take each one while doing the setup and save it in `docs/guides/instagram/images/` with the name used above (blur secrets, crop out emails).
