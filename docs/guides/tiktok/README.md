# TikTok setup guide (profiles)

How to create the TikTok developer app and connect a TikTok account to Postloom. Posting videos comes with piece 17.

**Video:** [`tiktok-setup.mp4`](tiktok-setup.mp4). Rebuild: `powershell -File scripts/make-guide-video.ps1 tiktok`

What you need: a TikTok account, a TikTok for Developers login (developers.tiktok.com, same TikTok login is fine), and Postloom running on **https** (TikTok refuses `http://localhost` redirect URLs). No app review is needed while the app stays in Sandbox.

---

## 1. Before setup

Accounts → TikTok shows "Add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to .env" and Connect is disabled.

![Before setup](images/01-connect-disabled-no-keys.png)

## 2. Run Postloom on https

TikTok only accepts an absolute `https://` redirect URL. With Herd:

```bash
herd secure postloom
```

Then open `https://postloom.test` and set in `.env`:

```
APP_URL=https://postloom.test
```

TikTok accepted `https://postloom.test` on 2026-11-11. If it ever refuses the `.test` domain on the redirect URL screen, use a tunnel instead (for example `cloudflared tunnel --url http://localhost:8000`) and use the tunnel's https URL everywhere below.

![https](images/02-https-postloom-test.png)

## 3. Create the developer app

developers.tiktok.com → **Manage apps** → **Connect an app**. Fill in:

| Field       | Value                                      |
| ----------- | ------------------------------------------ |
| App name    | `Postloom`                                 |
| Category    | Productivity (or closest)                  |
| Description | Personal scheduler for my own TikTok posts |
| App icon    | any 1024×1024 PNG                          |

![Create app](images/03-create-app.png)

## 4. Sandbox and target user

Switch the app view to **Sandbox** → **Create sandbox**. Under **Target users**, add your TikTok account (and any other account you want to connect, up to the sandbox limit). Only target users can log in while the app is in Sandbox.

![Sandbox](images/04-sandbox.png)

## 5. Products

**Add products** → **Login Kit** and **Content Posting API**. In Content Posting API, turn on **Direct Post**.

![Products](images/05-products.png)

## 6. Scopes

Make sure these scopes are on: `user.info.basic`, `user.info.profile`, `video.upload`, `video.publish`.

![Scopes](images/06-scopes.png)

## 7. Redirect URL

Login Kit → **Web** → **Redirect URI**, add exactly:

```
https://postloom.test/accounts/tiktok/callback
```

No query string, no `#`, must match `TIKTOK_REDIRECT_URI` character for character.

![Redirect URL](images/07-redirect-url.png)

## 8. Keys into `.env`

From the sandbox's **Credentials**:

```
TIKTOK_CLIENT_KEY=...
TIKTOK_CLIENT_SECRET=...
TIKTOK_REDIRECT_URI=https://postloom.test/accounts/tiktok/callback
```

Never paste the secret in chat, commits or screenshots (blur it).

![Keys](images/08-keys.png)

## 9. Restart and connect

`php artisan config:clear`, `stop.ps1`, `start.ps1` → open `https://postloom.test` → Accounts → TikTok → **Connect** → log in with a target-user account → **Authorize**.

![Connect](images/09-connect.png)

## 10. Connected

The account shows **Connected** and "Token: renews automatically". The daily token check refreshes it every morning.

![Connected](images/10-connected.png)

---

## Good to know before posting (piece 17)

- Until TikTok audits the app, direct posts are **private (only you)** and the TikTok account must be set to **private**. At most 5 accounts can post per 24 hours.
- "Send to TikTok inbox" uploads the video as a draft; you finish and post it publicly in the TikTok app.
- Photo posts need a public, verified domain, so Postloom posts videos only for now.

## If something goes wrong

- **redirect_uri error on TikTok:** the Redirect URI in Login Kit and `TIKTOK_REDIRECT_URI` differ, or you opened Postloom on another host than `APP_URL`.
- **"The TikTok login expired":** the session was lost between Postloom and TikTok. Open Postloom on the same https host as the redirect URL and connect again.
- **Account can't log in on TikTok:** it isn't a target user of the sandbox.

More in the `tiktok` skill's debug table (`.claude/skills/tiktok/SKILL.md`).

## Screenshots still to add

- All of `images/01-…` to `images/10-…` (take them while doing the setup).
