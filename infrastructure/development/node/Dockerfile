# SPDX-License-Identifier: Apache-2.0
#
# Node runtime for local development of the web workspace. See
# docs/decisions/0003-development-runtimes-in-containers.md,
# docs/decisions/0007-node-24-pnpm-and-typescript-6-for-the-web-workspace.md
# and docs/decisions/0009-shadcn-vue-in-the-ui-package-and-browser-mode-tests.md
#
# The Node and pnpm versions must equal web/.nvmrc and the packageManager
# fields of web/package.json. The smoke test compares them.

FROM node:24.21.0-trixie

RUN npm install --global pnpm@12.8.1 \
    && npm cache clean --force

# Chromium's headless shell for the component tests of @hostmesh/ui, which
# run in Vitest browser mode. PLAYWRIGHT_VERSION must equal the playwright
# entry of the catalog in web/pnpm-workspace.yaml. The smoke test compares
# them. The browser lives outside the home directory and is readable by any
# user, because the container runs as the host user.
ENV PLAYWRIGHT_VERSION=1.63.0 \
    PLAYWRIGHT_BROWSERS_PATH=/opt/playwright

RUN npx --yes playwright@${PLAYWRIGHT_VERSION} install --with-deps --only-shell chromium \
    && chmod -R a+rX /opt/playwright \
    && npm cache clean --force \
    && rm -rf /var/lib/apt/lists/*

# The container runs as the host user, who has no home directory inside it.
# pnpm keeps its store on the workspace's filesystem, in
# web/node_modules/.pnpm-store, so packages are hard-linked, not copied.
ENV HOME=/tmp/home

WORKDIR /workspace/web
