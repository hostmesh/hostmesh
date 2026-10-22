# 0023. Changing and resetting passwords

- Status: accepted
- Date: 2026-10-22
- Issue: Change and reset a password (#133)

## Context

Nothing changed a password. An administrator or a customer who lost one could not get back in; the operator created another account. The spec says nothing about changing or resetting a password, or about a password rule (#132 deliberately invented none).

Checked on 2026-10-22:

- **NIST SP 800-63B-4** (final): a password that may be the only factor has at least 15 characters; one used only with a second factor at least 8. Allow at least 64 characters. Refuse values on a list of common, expected or compromised passwords. Impose no other composition rule.
- **Hashing:** bcrypt, Laravel's default here (`BCRYPT_ROUNDS=12`), reads only the first 72 bytes of a password.
- **Breach check:** Laravel's `Password::uncompromised()` sends the first five characters of the password's SHA-1 to `api.pwnedpasswords.com` (k-anonymity) and accepts the password when the service cannot be reached. It waits 30 seconds by default.
- **Email:** the control plane sends none (`MAIL_MAILER=log`, no mail classes), so a reset link would need the whole mail path first.
- **Sessions:** both realms use Sanctum's `AuthenticateSession` (through `UseSessionRealm`), which ends a session whose stored password hash no longer matches the account's.

## Options considered

- **Reset:** an emailed link now (mail path first), or operator commands now and the link later.
- **Length:** one rule (15) for both realms, or 8 where a second factor is always required (admin) and 15 in the portal.
- **Blocklist:** Pwned Passwords failing open, failing closed, or a list shipped with the control plane.
- **Change:** the current password only, or as the other account actions ask: the current password and a code where two-factor is on.

## Decision

Chosen by the owner on 2026-10-22.

- **Changing one's own password,** in both realms: `POST /api/v1/{admin,customer}/auth/password` with `current_password`, `new_password` and, where two-factor is on (always in admin), `code` from the authenticator, as turning two-factor off asks. The password is checked before the code, so a wrong password does not spend the code. The account's other sessions are deleted from its realm's session table in the same transaction as the change and its audit event `auth.password_changed`. The session that asked goes on: the account object is refreshed, so Sanctum stores the new hash. Policy ability `changePassword`, reported as `change_password`; only for one's own account. Limiter `password`: 5 a minute per account, 20 per address, in each realm. Both account screens have the form.
- **The rule:** 15 characters to 72 bytes in both realms, no other composition rule. The byte limit is bcrypt's; 72 plain characters, fewer accented letters or symbols.
- **The blocklist:** Pwned Passwords through `uncompromised()`, failing open, with a 5-second timeout instead of Laravel's 30. `HOSTMESH_PASSWORD_BREACH_CHECK=false` switches it off for an installation without outbound access.
- **A lost password,** for now: an operator with shell access runs `administrator:reset-password <email>` or `customer:reset-password <email>`. A new password of 40 random characters is printed once; the account's sessions end; two-factor stays as it was; `administrator.password_reset` or `user.password_reset` is recorded with the system as actor. An emailed link for customers comes with the mail work of Slice 6 (#260).

## Consequences

- The rule applies to passwords people choose. Generated passwords (40 random characters, from the create and reset commands) meet it.
- Existing passwords are not checked against the rule until they are changed.
- The breach check makes one outbound HTTPS request per change, and may skip the check when the service is slow or down.
- Spec section 28 does not describe any of this yet: a `spec-change` issue carries it.
