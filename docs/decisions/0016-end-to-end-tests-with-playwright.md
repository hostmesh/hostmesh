# 0016. End-to-end tests: Playwright in the node service against an end-to-end mode of the stack

- Status: accepted
- Date: 2026-10-13
- Issue: Playwright: log in through both Vue shells (Slice 0 exit test) (#32)

## Context

Slice 0 ends with "a login through the Vue shell" in a green pipeline (spec section 33), and Playwright "covers critical flows against the real API" (section 37). The development stack serves both apps from Vite's dev servers and has one database. The test must run the built static apps, must not touch development data, must not be flaky, and must be a required status check.

Three facts shaped the design:

- A TOTP code is accepted once per account (decision 0011), and login is limited to 20 attempts a minute per address and realm.
- Chromium on Linux trusts only the authorities in its NSS database, not the system store that `make trust` fills. Measured on 2026-10-13 with Playwright 1.64.0 in the `node` service: it reads `$HOME/.pki/nssdb` whenever that directory exists, even when it is empty, and `$HOME/.local/share/pki/nssdb` only when it does not.
- A required status check that a path filter skips blocks a pull request for good.

## Options considered

**Where the apps come from.** (a) A compose overlay that switches the same hostnames to the built apps and a second database. (b) A second hostname family (`*.e2e.hostmesh.test`) with its own PHP service, next to development. (c) The development stack as it is. Option (c) tests Vite's dev server and overwrites local branding. Option (b) lets both run at once at the price of a second set of hostnames, certificates and services. Option (a) is one small file and keeps the documented hostnames.

**Where Playwright runs.** In the `node` service, or on the host and the CI runner. On the host it needs Node, pnpm and Chromium outside the containers (against decision 0003), and hosts entries and trust set up on every runner.

**Where the data comes from.** Factories from a test helper, a seeder, or a guarded console command that prints what it made.

## Decision

- `infrastructure/development/compose.e2e.yaml` and `Caddyfile.e2e`: Caddy serves `web/apps/*/dist` with the security headers of the Nginx example, and `php` and `worker` run with `APP_ENV=e2e`, the database `hostmesh_e2e` and Redis databases 2 and 3. `make e2e-up` switches to this mode and `make up` back.
- Playwright Test runs in the `node` service, locally and in CI. Caddy has network aliases for the four hostnames, and `make e2e-up` imports Caddy's local CA into `$HOME/.pki/nssdb` of that container, the directory that always wins. `@playwright/test` is pinned to the exact version of `playwright` (decision 0009), so the image's Chromium build serves both, and Dependabot updates the two in one pull request.
- `php artisan e2e:seed` creates the data and prints the generated passwords and TOTP secrets as JSON, which `make e2e` writes to the git-ignored `web/e2e/.seed.json`. The command is registered only when `APP_ENV` is `e2e` or `testing`, and refuses again when it runs. Console commands become an entry point next to `Http`; an architecture test keeps anything from depending on them.
- One administrator per test that passes a challenge. Every run reseeds and flushes the end-to-end cache. `retries` is 0.
- TOTP codes in the tests come from `otpauth` (MIT).
- The workflow `End-to-end` has no path filter, and its job is a required status check of `main`.

## Consequences

- Development and end-to-end mode cannot run at the same time, and switching recreates three containers.
- The suite runs on every pull request, including ones that change only documentation.
- The Content Security Policy now exists in three places (the configuration, the Nginx example, the end-to-end Caddyfile); the Pest tests of `ContentSecurityPolicyTest.php` compare both copies with the configuration.
- A page error or a policy violation fails every test, so the suite also guards the production headers. Its first run found one: the color mode composable added a `<style>` element that `style-src 'self'` blocks (issue #95).
- `--repeat-each` trips the login throttle. Flakiness is looked for by repeating `make e2e`.
- A new CA after a volume reset needs `make e2e-up` again, which rebuilds the NSS database.
- Later slices add their flows (node enrollment, provisioning, checkout) as spec files and fixtures here.
