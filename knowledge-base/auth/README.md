# Auth

## Purpose

Postloom has one owner. Logging in to the app is email + password (with optional two-factor and passkeys). Social accounts are connected separately later, only for publishing.

## Flow

1. **First visit, no user in the database:** the Welcome and Login pages show "Register" / "Sign up". The owner registers and is logged in.
2. **After that:** registration is closed. `/register` redirects to `/login`, the sign-up links are hidden, and a direct `POST /register` fails with "Registration is closed."
3. **Login:** Fortify checks email + password, then two-factor if the owner turned it on. Passkeys can log in without a password.
4. **Forgot password:** Fortify sends a reset link. Mail is set to `log`, so the link is written to `storage/logs/laravel.log` (search for `reset-password`).
5. Every auth event is logged (see Logging).

## Entry points

- Fortify routes: `login`, `login.store`, `logout`, `register`, `register.store`, `password.request`, `password.email`, `password.reset`, `password.update`, two-factor and passkey routes (`php artisan route:list --path=login`).
- `app/Services/RegistrationGate.php`: `isOpen()` = `config('postloom.allow_registration')` or no user exists, deleted users included (`User::withTrashed()`), so deleting the owner account does not reopen sign-up.
- `app/Providers/FortifyServiceProvider.php`: register view redirects to login when the gate is closed.
- `app/Actions/Fortify/CreateNewUser.php`: blocks registration on the server when the gate is closed.
- `app/Http/Middleware/HandleInertiaRequests.php`: shares `canRegister` (guests only) with every page.
- `app/Listeners/LogAuthEvent.php`: auto-discovered listener for `Login`, `Failed`, `Logout`, `Lockout`, `Registered`, `PasswordReset`.
- Pages: `resources/js/pages/auth/*`, `resources/js/pages/Welcome.vue`, settings pages under `resources/js/pages/settings`.

## Data

- `users` (name, email, password, two-factor columns), `passkeys`, `sessions`, `password_reset_tokens`.
- `email_verified_at` still exists on `users` but is not used.

## Rules

- Only one owner. A second account needs `POSTLOOM_ALLOW_REGISTRATION=true` in `.env` (turn it back off after).
- Email verification is turned off (no mail server, single owner). The `verified` middleware is not used.
- Login is rate-limited to 5 attempts per minute per email + IP (Fortify `login` limiter); two-factor to 5 per minute.

## Logging

Feature `auth` (`LogFeature::Auth`). Context always has `event` (`login`, `failed`, `logout`, `lockout`, `registered`, `password_reset`, `registration_blocked`) and `ip`.

| Event           | Level   | Message                                                | Context                               |
| --------------- | ------- | ------------------------------------------------------ | ------------------------------------- |
| Login           | info    | `User logged in`                                       | `user_id`, masked `email`, `remember` |
| Failed          | warning | `Login failed`                                         | masked `email`, `user_exists`         |
| Logout          | info    | `User logged out`                                      | `user_id`, masked `email`             |
| Lockout         | warning | `Too many login attempts, login locked`                | masked `email`                        |
| Registered      | info    | `Owner account registered`                             | `user_id`, masked `email`             |
| PasswordReset   | info    | `Password reset`                                       | `user_id`, masked `email`             |
| Blocked sign-up | warning | `Registration blocked because an owner already exists` | none                                  |

Emails are masked as `wa***@domain.com`. Passwords and tokens are never logged.

## Config (`.env`)

- `POSTLOOM_ALLOW_REGISTRATION=false` (`config/postloom.php`)
- `MAIL_MAILER=log` (reset links go to `storage/logs/laravel.log`)

## Tests

- `tests/Feature/Auth/SingleOwnerRegistrationTest.php`: open with no users, closed after (page and POST), env switch reopens it.
- `tests/Feature/Auth/AuthEventLoggingTest.php`: each event writes the right message and masked context, no password.
- Starter kit tests: `AuthenticationTest`, `RegistrationTest`, `PasswordResetTest`, `PasswordConfirmationTest`, `TwoFactorChallengeTest`, `tests/Feature/Settings/*`.

## Known limits

- If the database is wiped, registration opens again until someone signs up.
- Deleting your account (Settings → Profile) is a soft delete: the user gets `deleted_at` and can no longer log in; its data stays.
- No real email delivery; set up a mailer before the app runs anywhere other than this PC.
