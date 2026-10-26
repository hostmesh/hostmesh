# SPDX-License-Identifier: Apache-2.0
#
# Node runtime for local development of the web workspace. See
# docs/decisions/0003-development-runtimes-in-containers.md,
# docs/decisions/0007-node-24-pnpm-and-typescript-6-for-the-web-workspace.md
# and docs/decisions/0009-shadcn-vue-in-the-ui-package-and-browser-mode-tests.md
#
# The Node and pnpm versions must equal web/.nvmrc and the packageManager
# fields of web/package.json. The smoke test compares them.

FROM node:24.22.1-trixie

RUN npm install --global pnpm@12.8.1 \
    && npm cache clean --force

# Chromium's headless shell for the component tests of @hostmesh/ui, which
# run in Vitest browser mode. PLAYWRIGHT_VERSION must equal the playwright
# entry of the catalog in web/pnpm-workspace.yaml. The smoke test compares
# them. The browser lives outside the home directory and is readable by any
# user, because the container runs as the host user.
ENV PLAYWRIGHT_VERSION=1.64.0 \
    PLAYWRIGHT_BROWSERS_PATH=/opt/playwright

RUN npx --yes playwright@${PLAYWRIGHT_VERSION} install --with-deps --only-shell chromium \
    && chmod -R a+rX /opt/playwright \
    && npm cache clean --force \
    && rm -rf /var/lib/apt/lists/*

# certutil, for the end-to-end tests: Chromium trusts only the certificate
# authorities in its NSS database, and `make e2e-up` imports Caddy's local
# CA there (decision 0016).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libnss3-tools \
    && rm -rf /var/lib/apt/lists/*

# The container runs as the host user, who has no home directory inside it.
# pnpm keeps its store on the workspace's filesystem, in
# web/node_modules/.pnpm-store, so packages are hard-linked, not copied.
# Set explicitly: by default pnpm picks the store by working directory, and
# from web/packages/ui it made a second one in web/.pnpm-store (issue #146).
# pnpm 12 reads its settings from pnpm_config_* variables, not npm_config_*.
ENV HOME=/tmp/home \
    pnpm_config_store_dir=/workspace/web/node_modules/.pnpm-store

WORKDIR /workspace/web
