# 0011. TOTP two-factor authentication on google2fa

- Status: accepted
- Date: 2026-10-05
- Issue: Two-factor authentication (TOTP), required for admin (#25)

## Context

Spec section 28 requires two-factor authentication for the admin realm and two-factor flows in the API. Decision 0010 gave each realm its own guard and session. The owner's requirements for #25: secrets encrypted at rest, recovery codes stored hashed and single use, a code refused when it is replayed within its window, mandatory for administrators and optional for customers.

Checked on Packagist on 2026-10-05: `laravel/fortify` 1.40.0, `pragmarx/google2fa` 9.1.0 (MIT), `spomky-labs/otphp` 11.5.0 (MIT).

## Options considered

1. **Laravel Fortify's two-factor feature.** It reads one `fortify.guard`, so two realms need per-request configuration switching again. It stores recovery codes encrypted, which is reversible. Its replay protection is a cache entry, and Redis must not carry correctness. It brings its own controllers, routes and actions, which bypass the application layer, and requires `laravel/passkeys` and `bacon/bacon-qr-code`. It has no notion of a mandatory second factor, so the gate is ours either way.
2. **`spomky-labs/otphp`.** A clean TOTP library, but `verify()` returns a boolean, so the accepted time step must be searched for again to refuse a replay.
3. **`pragmarx/google2fa` behind our own interface.** Chosen. It is the engine Fortify itself uses, has one small dependency, and `verifyKeyNewer()` returns the accepted time step.

## Decision

- `App\Domain\Identity\TwoFactor\Totp` is the interface; `App\Infrastructure\TwoFactor\Google2faTotp` implements it. SHA-1, 6 digits, 30-second steps, one step of tolerance either way.
- Password login authenticates the guard. The session additionally needs a second-factor mark bound to the account ID. The `two-factor` middleware refuses every gated route without it, with 403 and a `code` of `two_factor_required` or `two_factor_enrollment_required`.
- `hostmesh.realms.<name>.two_factor_required` makes it mandatory. It is `true` for admin and not configurable through the environment.
- Columns on `administrators` and `users`: `two_factor_secret` (encrypted cast), `two_factor_confirmed_at`, `two_factor_last_step`, `two_factor_recovery_codes` (JSON list of SHA-256 hashes).
- A code is accepted only for a time step after `two_factor_last_step`. Codes are checked inside a transaction on a locked account row.
- Recovery codes carry 100 bits of randomness, so a fast hash is sufficient; they are compared with `hash_equals`.
- The API returns the secret and the `otpauth://` URI. The client renders the QR code.

## Consequences

- The login response changes: `two_factor_enabled` on the account and `meta.second_factor`.
- Every admin route added later must sit inside the `two-factor` group. `tests/Feature/Auth/SecondFactorGateTest.php` reads the route list and fails otherwise.
- An administrator who loses both the device and the recovery codes cannot log in. An operator command to reset them is a follow-up.
- The admin enrollment and challenge screens follow with the login shells (#31).
