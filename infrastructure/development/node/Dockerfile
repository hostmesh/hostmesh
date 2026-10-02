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
# pnpm keeps its store in $PNPM_HOME/store, on the pnpm_home volume. The
# directory is world-writable so that the volume created from it is too.
ENV HOME=/tmp/home \
    PNPM_HOME=/pnpm

RUN mkdir -p /pnpm && chmod 1777 /pnpm

WORKDIR /workspace/web
