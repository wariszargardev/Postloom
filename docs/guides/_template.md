# <Platform> setup guide (<account type>)

How to create the <Platform> developer app, connect an account to Postloom and send the first post.

**Video:** [`<platform>-setup.mp4`](<platform>-setup.mp4). Rebuild: `powershell -File scripts/make-guide-video.ps1 <platform>`

What you need: <accounts, pages or channels the platform requires; any review or verification that takes days>.

---

## 1. Before setup

<What Accounts shows before the keys are set.>

![Before setup](images/01-before-setup.png)

## 2. Create the developer app

Open <developer console URL> → <button>. Fill in:

| Field    | Value      |
| -------- | ---------- |
| App name | `Postloom` |
| ...      | ...        |

![Create app](images/02-create-app.png)

## 3. Add products / permissions

<Which products, scopes or permissions to request; which need review and how long it takes.>

![Permissions](images/03-permissions.png)

## 4. Redirect URL

Add exactly:

```
http://localhost:8000/accounts/<platform>/callback
```

![Redirect URL](images/04-redirect-url.png)

## 5. Keys into `.env`

```
<PLATFORM>_CLIENT_ID=...
<PLATFORM>_CLIENT_SECRET=...
<PLATFORM>_REDIRECT_URI=http://localhost:8000/accounts/<platform>/callback
```

Never paste the secret in chat, commits or screenshots (blur it).

![Keys](images/05-keys.png)

## 6. Restart and connect

`php artisan config:clear`, `stop.ps1`, `start.ps1` → Accounts → <Platform> → **Connect** → sign in → **Allow**.

![Connect](images/06-connect.png)

## 7. First post

<Platform-specific media rules.> Schedule or Publish now, then check **View post**.

![Published](images/07-published.png)

---

## If something goes wrong

<Top 3 errors and their fixes; link the platform's skill debug table.>

## Screenshots still to add

- <list missing images>
