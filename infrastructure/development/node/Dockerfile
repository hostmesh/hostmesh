# SPDX-License-Identifier: Apache-2.0
#
# Node runtime for local development of the web workspace. See
# docs/decisions/0003-development-runtimes-in-containers.md and
# docs/decisions/0007-node-24-pnpm-and-typescript-6-for-the-web-workspace.md.
#
# The Node and pnpm versions must equal web/.nvmrc and the packageManager
# fields of web/package.json. The smoke test compares them.

FROM node:24.21.0-trixie

RUN npm install --global pnpm@12.8.1 \
    && npm cache clean --force

# The container runs as the host user, who has no home directory inside it.
# pnpm keeps its store on the workspace's filesystem, in
# web/node_modules/.pnpm-store, so packages are hard-linked, not copied.
ENV HOME=/tmp/home

WORKDIR /workspace/web
