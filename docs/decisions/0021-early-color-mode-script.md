# 0021. The early color-mode script is a file from 'self'

- Status: accepted
- Date: 2026-10-22
- Issue: Apply the dark class before first paint (#142)

## Context

Spec section 29: the default color mode follows the system. `useColorMode()` (`@hostmesh/ui`, VueUse 14) sets `light` or `dark` on `<html>` when the app's bundle runs. The bundle is a module script, which runs after the page is parsed, so a dark system showed the light page first.

Avoiding that needs a script that runs before the first paint: a classic script in `<head>`. Both apps send `script-src 'self'` with no `'unsafe-inline'` (spec section 28), defined in `control-plane/config/hostmesh.php` and copied into `infrastructure/nginx/hostmesh.conf.example` and `infrastructure/development/Caddyfile.e2e`, which tests keep in step.

## Options considered

1. **A script file from `'self'`.** Allowed by the policy as it is. One small request on the first load, which blocks rendering, then cached.
2. **An inline script and its hash in `script-src`.** No request. The hash is in the policy in four places, and any change to the script, or to how Vite writes `index.html`, changes all four.

## Decision

Chosen by the owner on 2026-10-22: option 1.

- `@hostmesh/ui/vite` exports `colorModeEarly()`, a Vite plugin that both apps use. It adds `<script src="/assets/color-mode-<hash>.js">` to `<head>`, serves the file from the development server and emits it into the build. The name carries a hash of the content, so a cached copy is never older than the page.
- The script does what `useColorMode()` does: a stored `light` or `dark` under `hostmesh-color-mode`, else the system's `prefers-color-scheme`. Storage that cannot be read counts as no choice. It sets the class only; `useColorMode()` takes over when the app starts.
- The storage key has one definition (`COLOR_MODE_STORAGE_KEY`), in a module without dependencies, so the plugin reads the same key as the app.
- The policy does not change.

## Consequences

- Every page load runs one script outside the bundle; it is 0.33 kB.
- A change to how `useColorMode()` stores or applies the mode (a VueUse upgrade, other classes) must be made in the early script too. `src/vite/color-mode.test.ts` checks the script against the stored values and the system setting; `web/e2e/tests/color-mode.spec.ts` checks both apps with the bundle held back.
- The plugin is loaded by Node from the TypeScript source when Vite reads its configuration, so its imports name files the way Node resolves them: the package's subpath imports (`#ui/composables/…`), not relative paths without an extension.
