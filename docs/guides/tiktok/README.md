# TikTok setup guide (profiles)

How to create the TikTok developer app, connect TikTok accounts to Postloom, and add more accounts later. Posting videos comes with piece 17.

**Video:** [`tiktok-setup.mp4`](tiktok-setup.mp4). Rebuild: `powershell -File scripts/make-guide-video.ps1 tiktok`

What you need: a TikTok account, a TikTok for Developers login (developers.tiktok.com), and Postloom reachable on **https** for the connect step only (TikTok refuses `http://localhost` redirect URLs). No app review is needed while the app stays in Sandbox.

---

## Our current setup (2026-11-11)

Everything below is what is configured today, so it can be rebuilt the same way. **No secrets here:** the client key and secret live only in `.env` and on the TikTok portal.

| What                   | Value                                                                                                                                              |
| ---------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| Developer portal login | TikTok for Developers account opened in the **Waris** Chrome profile (wariszargardev)                                                              |
| App                    | **Postloom Scheduler** (`Postloom` was already taken), ownership Individual, type Other                                                            |
| App page               | https://developers.tiktok.com/app/7706809468248672276                                                                                              |
| Mode in use            | **Sandbox** "Postloom local": https://developers.tiktok.com/app/7706809468248672276/sandbox/7706807661484099590                                    |
| Category / description | Business / "Personal scheduler to plan and post my own videos to TikTok."                                                                          |
| Platform               | Web, Web/Desktop URL `https://postloom.test`                                                                                                       |
| Terms / Privacy URLs   | `https://postloom.test/terms`, `https://postloom.test/privacy` (placeholders; Sandbox doesn't check them)                                          |
| App icon               | Plain 1024×1024 PNG (navy square, green and amber boxes)                                                                                           |
| Products               | Login Kit (Web), Content Posting API with **Direct Post** on                                                                                       |
| Scopes                 | `user.info.basic`, `user.info.profile`, `video.upload`, `video.publish`                                                                            |
| Redirect URI (Web)     | `https://postloom.test/accounts/tiktok/callback`                                                                                                   |
| Target users           | `waris.zargar2` and `fruitpuraiurdu`, both connected (2026-11-11). Removing one from this list blocks its next login; add it back with Add account |
| Keys used in `.env`    | The **Sandbox** "Postloom local" Credentials (not the Production tab's)                                                                            |

`.env` lines (values only on your PC):

```
APP_URL=http://localhost:8000
TIKTOK_CLIENT_KEY=...            # Sandbox → Credentials → Client key
TIKTOK_CLIENT_SECRET=...         # Sandbox → Credentials → Client secret (never in chat or git)
TIKTOK_REDIRECT_URI=https://postloom.test/accounts/tiktok/callback
```

### Two addresses, on purpose

| Use                                                 | Address                 |
| --------------------------------------------------- | ----------------------- |
| Everything daily: posts, calendar, publishing       | `http://localhost:8000` |
| Connect / Reconnect **YouTube, Facebook, LinkedIn** | `http://localhost:8000` |
| Connect / Reconnect **TikTok** only                 | `https://postloom.test` |

Each platform's callback URL is its own `.env` key, so both work side by side. A login only works when it starts on the same address its callback returns to. Google refuses `.test` domains, so YouTube must stay on localhost. You are logged in to Postloom separately on each address (and per Chrome profile).

---

## 1. Before setup

Accounts → TikTok shows "Add TIKTOK_CLIENT_KEY and TIKTOK_CLIENT_SECRET to .env" and Connect is disabled.

![Before setup](images/01-connect-disabled-no-keys.png)

## 2. https for the connect step

```bash
herd secure postloom
```

This serves the same Postloom at `https://postloom.test`. `APP_URL` stays `http://localhost:8000`; TikTok uses `TIKTOK_REDIRECT_URI`. TikTok accepted `https://postloom.test` on 2026-11-11. If it ever refuses the `.test` domain, use a tunnel (for example `cloudflared tunnel --url http://localhost:8000`) and its https URL instead.

![https](images/02-https-postloom-test.png)

## 3. Create the developer app

developers.tiktok.com → **Manage apps** → **Connect an app** → ownership **Individual** → app name (we used `Postloom Scheduler`) → app type **Other** → **Create app**.

![Create app](images/03-create-app.png)

## 4. Sandbox

Switch to **Sandbox** → **Create Sandbox** → name `Postloom local`. Do all of the next steps inside the sandbox, then press **Apply changes** at the top right. If it says "This form has 1 error", click **Review** (last time it was the empty Web/Desktop URL).

Basic information (all required, even in Sandbox): app icon, category, description, Terms of Service URL, Privacy Policy URL, Platforms **Web**, Web/Desktop URL. Values are in the table above.

![Sandbox](images/04-sandbox.png)

## 5. Products

**Add products** → **Login Kit** → Add, **Content Posting API** → Add → Done. In Content Posting API turn on **Direct Post**.

![Products](images/05-products.png)

## 6. Scopes

`user.info.basic`, `video.upload` and `video.publish` come with the products. **Add scopes** → `user.info.profile` → Add.

![Scopes](images/06-scopes.png)

## 7. Redirect URL

Login Kit → **Web** → Redirect URI, exactly:

```
https://postloom.test/accounts/tiktok/callback
```

No query string, no `#`, must match `TIKTOK_REDIRECT_URI` character for character. Then **Apply changes** (toast "Saved").

![Redirect URL](images/07-redirect-url.png)

## 8. Target users

**Sandbox settings → Target Users → Add account** → log in with the TikTok account you will post to → approve. Up to 10 accounts. Only these accounts can connect while the app is in Sandbox; any other gets TikTok's `non_sandbox_target` page.

![Target users](images/08-target-users.png)

## 9. Keys into `.env`

Sandbox → **Credentials** → copy Client key and Client secret into `.env` yourself (lines above). Never paste the secret in chat, commits or screenshots (blur it). Then:

```bash
php artisan config:clear
```

and `stop.ps1`, `start.ps1`.

![Keys](images/09-keys.png)

## 10. Connect

Open `https://postloom.test/accounts` (log in to Postloom there if asked) → TikTok → **Connect** → on TikTok make sure the right account is shown (use **Switch account** if not) → **Continue**. Back on Accounts: "<name> is connected", "Token: renews automatically".

![Connected](images/10-connected.png)

---

## Add another TikTok account (one time per account)

1. In the **Waris** Chrome profile open the sandbox page (link in the table) → **Sandbox settings → Target Users → Add account** → log in with the new TikTok account → approve → **Apply changes** if shown.
2. In the Chrome profile where that TikTok account is logged in, open `https://postloom.test/accounts` → log in to Postloom → **TikTok → Connect → Continue**.

Done for good: Postloom renews each account's login every day, so you never repeat this unless TikTok shows **Reconnect** (then only step 2).

## Rebuild from zero (new PC or lost app)

1. Herd + `herd secure postloom` (step 2).
2. If the app still exists: copy the Sandbox keys into `.env` (step 9), then Connect (step 10).
3. If the app is gone: steps 3–9 with the values in the table, then Connect each account again (target users must be added again in the new app).

---

## Posting to TikTok

**Use "I'll post it myself (public)"** (the default under Per account → TikTok → How to post):

1. Schedule the post in Postloom as usual (one video, caption, time).
2. At the time, the TikTok account shows **Ready for you** and the post appears under **To post** (sidebar badge and a banner).
3. On the PC: **Download video**, **Copy caption**, **Open TikTok Studio** → Upload → pick the file → paste the caption → choose Everyone → Post. You can also use TikTok Studio's own schedule there.
4. Back in Postloom: paste the TikTok link (optional) → **Mark as posted**. The account becomes Published with that link.

Why not automatic: until TikTok audits the app, direct posts are private only (and the account must be private), and inbox drafts from the Sandbox never reached the TikTok app in our test. TikTok's guidelines reject "a utility tool to help upload contents to the account(s) you or your team manages", so an audit won't fix it for Postloom. The other modes stay for tests:

- **Post directly**: "Only me" only, TikTok account set to private first.
- **Send to TikTok inbox**: TikTok says "sent", but nothing arrives while the app is in Sandbox.
- Photo posts need a public, verified domain, so TikTok is video only.

## If something goes wrong

- **`non_sandbox_target` on TikTok:** the TikTok account isn't a target user. Add it (section above) or switch to a listed account.
- **redirect_uri error on TikTok:** the Redirect URI in Login Kit and `TIKTOK_REDIRECT_URI` differ.
- **"The TikTok login expired":** Connect was started on another address than the callback (use `https://postloom.test`), or the callback was opened twice. Connect again.
- **A connected account disappeared from Target Users** (removed by mistake): add it back with **Add account**, log in as that account in the popup (Switch account if another one is logged in), approve.
- **Developer portal shows "No access":** that Chrome profile isn't logged in to TikTok for Developers; use the Waris profile.

More in the `tiktok` skill's debug table (`.claude/skills/tiktok/SKILL.md`).

## Screenshots still to add

- All of `images/01-…` to `images/10-…` (take them while doing the setup).
