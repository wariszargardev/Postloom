---
name: auth
description: Postloom login and owner account — use when changing login, logout, registration, password reset, two-factor or passkeys, when someone can't log in or gets "Registration is closed", when a second account is needed (POSTLOOM_ALLOW_REGISTRATION), or when reading auth logs (failed logins, lockouts). Covers RegistrationGate, the Fortify register view and CreateNewUser block, the canRegister shared prop and the LogAuthEvent listener.
---

# Auth

KB: `knowledge-base/auth/README.md`.

## Key files

- `app/Services/RegistrationGate.php`
- `app/Actions/Fortify/CreateNewUser.php`
- `app/Providers/FortifyServiceProvider.php`
- `app/Http/Middleware/HandleInertiaRequests.php` (`canRegister`)
- `app/Listeners/LogAuthEvent.php`
- `config/fortify.php`, `config/postloom.php`

## Common tasks

- **Deleted owner:** profile delete is a soft delete; registration stays closed (`User::withTrashed()` in the gate). Restore the row (`deleted_at = null`) to get back in.
- **Allow a second account:** set `POSTLOOM_ALLOW_REGISTRATION=true` in `.env`, run `php artisan config:clear`, register, then set it back to `false`.
- **Forgot password:** use "Forgot password", then find the link in `storage/logs/laravel.log` (search `reset-password`).
- **Locked out by the rate limit:** wait one minute, or run `php artisan cache:clear`.

## Debugging

1. In `storage/logs/postloom-YYYY-MM-DD.log`, filter lines where `extra.feature` is `auth`.
2. `extra.event` tells you what happened (`failed`, `lockout`, `registration_blocked`…); `extra.ip` shows where from.
3. `Login failed` with `user_exists: false` means the email is wrong; `true` means the password is wrong.
