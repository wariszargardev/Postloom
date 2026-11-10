# YouTube setup guide (channel)

How to create the Google Cloud project and OAuth client, connect a YouTube channel to Postloom, and add more channels. Takes about 15 minutes, once.

**Video:** `youtube-setup.mp4`. Build it once the screenshots are in `images/` (needs the video builder from piece 09):

```bash
powershell -File scripts/make-guide-video.ps1 youtube
```

What you need: the Google account that owns (or manages) your YouTube channel(s).

---

## 1. Before setup

Accounts → YouTube shows "Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to .env" and Connect is disabled.

![Before setup](images/01-connect-disabled-no-keys.png)

## 2. Create a Google Cloud project

Open <https://console.cloud.google.com/> → project picker (top left) → **New project** → name `Postloom` → **Create**, then select it.

![New project](images/02-new-project.png)

## 3. Turn on the YouTube Data API v3

**APIs & Services → Library** → search **YouTube Data API v3** → **Enable**.

![Enable API](images/03-enable-youtube-api.png)

## 4. Set up the OAuth consent screen

**Google Auth Platform** (or **APIs & Services → OAuth consent screen**) → **Get started**:

| Field              | Value        |
| ------------------ | ------------ |
| App name           | `Postloom`   |
| User support email | your email   |
| Audience           | **External** |
| Contact email      | your email   |

![Consent screen](images/04-consent-screen.png)

## 5. Add the scopes

**Data access → Add or remove scopes** → tick:

- `.../auth/youtube.upload` (upload videos)
- `.../auth/youtube.readonly` (read your channel name)

`openid`, `email` and `profile` are added by default. **Save**.

![Scopes](images/05-scopes.png)

## 6. Test users (or publish)

**Publish app** stays greyed out ("complete your configuration on the Branding page") until Branding has a home page link, a privacy policy link and an authorized domain you own. `localhost` and `github.io` don't count.

![Publish app disabled](images/06-publish-app.png)

So start in **Testing**: **Audience → Test users → Add users** → the Gmail that owns the channel → **Save**. Every Google login you connect (not every channel; brand channels under the same Gmail are covered) must be on this list, up to 100.

![Add test user](images/06b-add-test-user.png)

- In Testing, Google ends the login after **7 days**. The daily check then marks the channel **Reconnect needed**; one click on Reconnect fixes it.
- To stop the weekly reconnect later: put a home page and privacy page on a domain you own, add them on Branding with the domain under Authorized domains, then **Publish app**. Unverified is fine for your own channels: Google shows "Google hasn't verified this app" once (**Advanced → Go to Postloom**).

## 7. Create the OAuth client

**Clients → Create client**:

| Field                    | Value                                             |
| ------------------------ | ------------------------------------------------- |
| Application type         | **Web application**                               |
| Name                     | `Postloom local`                                  |
| Authorized redirect URIs | `http://localhost:8000/accounts/youtube/callback` |

Leave **Authorized JavaScript origins** empty. Putting the callback URL there fails with "URIs must not contain a path"; it belongs under **Authorized redirect URIs**. **Create**.

![OAuth client](images/07-oauth-client.png)

## 8. Copy the keys into `.env`

Copy **Client ID** and **Client secret** from the dialog (or the client's page):

```
GOOGLE_CLIENT_ID=...apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=...
YOUTUBE_REDIRECT_URI=http://localhost:8000/accounts/youtube/callback
```

Never paste the secret in chat, commits or screenshots (blur it in `08-client-id-secret.png`).

![Keys](images/08-client-id-secret.png)

## 9. Restart Postloom

```bash
php artisan config:clear
```

Run `stop.ps1`, then `start.ps1`. Accounts → YouTube → **Connect** is now active.

![Connect ready](images/09-accounts-connect-ready.png)

## 10. Connect a channel

Click **Connect** → pick your Google account → if you have brand channels, pick the **channel** → on the unverified warning click **Advanced → Go to Postloom** → tick **both** YouTube permissions → **Continue**.

You come back to Accounts with "<channel> is connected." The token line says **renews automatically**.

![Google consent](images/10-google-consent.png)

## 11. Add more channels

Click **Connect** again and pick a different channel (or Google account) on the Google screen. Each channel becomes its own account. To put a channel in another workspace, switch workspace first, then Connect.

![Channel connected](images/11-channel-connected.png)

---

## If something goes wrong

See `.claude/skills/youtube/SKILL.md` (debug table) and `knowledge-base/youtube/README.md`. Most common:

- **`redirect_uri_mismatch`**: the URI in step 7 must equal `YOUTUBE_REDIRECT_URI` exactly.
- **"Could not connect YouTube"** with `accessNotConfigured` in the log: step 3 was skipped.
- **Reconnect needed every week**: the app is still in Testing (step 6).
- **`access_blocked` / "has not completed the Google verification process"**: that Google login isn't a test user (step 6).

## Screenshots still to add

Save them in `docs/guides/youtube/images/` with these names (blur secrets, crop out emails):

- `01-connect-disabled-no-keys.png`, `02-new-project.png`, `05-scopes.png`, `07-oauth-client.png`, `08-client-id-secret.png`, `09-accounts-connect-ready.png`, `10-google-consent.png`
