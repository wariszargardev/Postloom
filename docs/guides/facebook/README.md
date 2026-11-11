# Facebook setup guide (Pages)

How to create the Meta app, connect your Facebook Page(s) to Postloom, and add more Pages. Takes about 15 minutes, once.

**Video:** [`facebook-setup.mp4`](facebook-setup.mp4). Rebuild it after adding screenshots:

```bash
powershell -File scripts/make-guide-video.ps1 facebook
```

What you need: a Facebook account that **manages at least one Facebook Page** (Facebook doesn't let apps post to personal profiles). To make a Page: facebook.com → Menu → Pages → Create new Page.

---

## 1. Before setup

Accounts → Facebook shows "Add FACEBOOK_CLIENT_ID and FACEBOOK_CLIENT_SECRET to .env" and Connect is disabled.

![Before setup](images/01-connect-disabled-no-keys.png)

## 2. Create the Meta app

Open <https://developers.facebook.com/apps> (log in with the Facebook account that manages the Page; accept the developer terms the first time) → **Create app**:

| Step                    | Choose                                               |
| ----------------------- | ---------------------------------------------------- |
| App details             | App name `Postloom`, your email                      |
| Use cases               | **Manage everything on your Page**                   |
| Business                | **I don't want to connect a business portfolio yet** |
| Requirements / Overview | **Create app**                                       |

![Create app](images/02-create-app.png)

## 3. Add the Page permissions

**Use cases → Manage everything on your Page → Customize** → under Permissions click **Add** next to:

- `pages_show_list`
- `pages_read_engagement`
- `pages_manage_posts`

![Permissions](images/03-permissions.png)

## 4. Redirect URL

**Facebook Login** (or **Facebook Login for Business**) **→ Settings → Valid OAuth Redirect URIs** → add exactly:

```
http://localhost:8000/accounts/facebook/callback
```

**Save changes.** If Meta asks you to add the login use case first, add **"Authenticate and request data from users with Facebook Login"**.

![Redirect URL](images/04-redirect-url.png)

## 5. Keys into `.env`

**App settings → Basic** → copy **App ID** and **App secret** (click Show):

```
FACEBOOK_CLIENT_ID=...
FACEBOOK_CLIENT_SECRET=...
FACEBOOK_REDIRECT_URI=http://localhost:8000/accounts/facebook/callback
```

Never paste the secret in chat, commits or screenshots (blur it in `05-app-id-secret.png`).

![Keys](images/05-app-id-secret.png)

## 6. Keep the app in Development mode

Leave **App mode: Development**. You are the app's admin, so you can use all three permissions on your own Pages **without App Review or business verification**. Going Live is only needed to serve Pages of people who have no role on the app.

![Development mode](images/06-development-mode.png)

## 7. Restart Postloom

```bash
php artisan config:clear
```

Run `stop.ps1`, then `start.ps1`. Accounts → Facebook → **Connect** is now active.

![Connect ready](images/07-accounts-connect-ready.png)

## 8. Connect your Pages

Click **Connect** → **Continue as …** → tick the **Pages** Postloom may use → keep every permission switched on → **Save**. Each ticked Page becomes its own account in the current workspace, and its token line says **doesn't expire**.

![Choose Pages](images/08-choose-pages.png)

## 9. More Pages, other workspaces

- New Page later: click **Connect** again and tick it (Facebook remembers the earlier ones).
- To put a Page in another workspace: switch workspace first, then Connect and tick only that Page. Pages you don't want can be disconnected on Accounts.

![Pages connected](images/09-pages-connected.png)

---

## If something goes wrong

See `.claude/skills/facebook/SKILL.md` (debug table) and `knowledge-base/facebook/README.md`. Most common:

- **"URL blocked" / "Can't load URL"**: step 4 redirect URI is missing or different.
- **"did not allow posting"**: `pages_manage_posts` was switched off on the Facebook screen, or not added in step 3.
- **"No Facebook Page was shared"**: no Page was ticked, or your account only has an analyst role on it.
- **Reconnect needed**: you changed your Facebook password, removed the app at facebook.com/settings → Business integrations, or lost your role on the Page.

## Screenshots still to add

All of them: take each one while doing the setup and save it in `docs/guides/facebook/images/` with the name used above (blur secrets, crop out emails).
