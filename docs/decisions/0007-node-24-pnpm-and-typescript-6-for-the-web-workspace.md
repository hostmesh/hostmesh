# 0007. Node 24, pnpm and TypeScript 6 for the web workspace

- Status: accepted
- Date: 2026-10-02
- Issue: Vue workspace (#21)

## Context

The web workspace (spec sections 27 and 29) needs a Node version, a package manager and a TypeScript version. They are fixed by `web/.nvmrc`, `web/package.json`, the `node` service of the development stack and CI, so they have to be chosen once.

Checked on 2026-10-02: Node 24.21.0 is the active long-term-support release, supported until 30 April 2028. Node 22 is in maintenance until April 2027, and Node 26 becomes long-term support on 28 October 2026. Vitest 5 requires Node `^22.12.0 || ^24.0.0 || >=26.0.0`, jsdom 30 requires `^22.22.2 || ^24.15.0 || >=26.0.0`, ESLint 10 requires `^20.19.0 || ^22.13.0 || >=24` and Vite 8 requires `^20.19.0 || >=22.12.0`.

pnpm 12.8.1 is the current release. It keeps the commands, settings and lockfile format of pnpm 11, and it does not resolve a version published less than a day ago (`minimumReleaseAge`, 1440 minutes by default).

TypeScript 7.0.2 is the `latest` release. vue-tsc 3.3 fails on it and typescript-eslint 8.71 accepts `>=4.8.4 <6.1.0`. TypeScript 6.0.3 is accepted by both. openapi-typescript 7.13.0 declares `typescript ^5.x`; its issue for TypeScript 6 is open, with reports that it builds and passes its tests unchanged.

## Options considered

Node version:

- **22.** Works, but leaves support in seven months.
- **24.** Active long-term support, accepted by every tool.
- **26.** Not long-term support yet.

Package manager:

- **npm workspaces.** No extra tool, but no catalog of shared versions and no strict dependency isolation between packages.
- **pnpm workspaces.** One catalog of versions for both apps, strict isolation, and a release-age rule by default.

TypeScript version:

- **5.9.3.** Inside every declared peer range, but it is the last 5.x release and receives no more fixes.
- **6.0.3 with one peer exception.** The maintained line that every tool but openapi-typescript declares.
- **7.0.2.** Excluded by vue-tsc and typescript-eslint.

Where Node runs is not reopened: decision 0003 puts it in the Compose project.

## Decision

The web workspace targets Node 24: `24.21.0` in `web/.nvmrc`, `"node": "^24.21.0"` in `web/package.json`, and the `node:24.21.0-trixie` image in development. pnpm is pinned to 12.8.1 by the `packageManager` field and installed at that version in the image. `web/pnpm-lock.yaml` is committed, and installs outside dependency changes use `--frozen-lockfile`.

TypeScript is `~6.0.3`. `web/pnpm-workspace.yaml` allows TypeScript 6 for openapi-typescript (`peerDependencyRules.allowedVersions`), and no other peer rule is relaxed.

The `node` service runs commands only (`docker compose exec node pnpm …`). It serves nothing: Caddy keeps its placeholders for `app.hostmesh.test` and `admin.hostmesh.test` until the apps are served by a later issue.

## Consequences

- The Node and pnpm versions appear in `web/.nvmrc`, `web/package.json` and the Dockerfile. The smoke test of the development stack fails when they differ.
- CI reads the same two files (`node-version-file`, `packageManager`) and runs on the runner, as decision 0003 allows.
- The peer exception is removed when openapi-typescript declares TypeScript 6. The drift check on the generated client reports any change in its output.
- Moving to TypeScript 7 waits for vue-tsc and typescript-eslint, and is a new decision.
- Raising the Node version is a new decision.
