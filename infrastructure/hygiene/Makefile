# SPDX-License-Identifier: Apache-2.0
#
# License hygiene. Run from the repository root with
# `make -C infrastructure/hygiene <target>`.

.PHONY: install lint lint-fix typecheck test check collect licenses notices notices-check spdx

# --locked fails when uv.lock is out of date instead of rewriting it.
UV_RUN := uv run --locked

install:
	uv sync --locked

lint:
	$(UV_RUN) ruff check .
	$(UV_RUN) ruff format --check .

lint-fix:
	$(UV_RUN) ruff format .
	$(UV_RUN) ruff check --fix .

typecheck:
	$(UV_RUN) mypy

test:
	$(UV_RUN) pytest

check: lint typecheck test

# The package listings, each written by its ecosystem's own tool. Locally
# Composer and pnpm run in the development stack; CI overrides both:
#   COMPOSER_COMMAND='composer --working-dir=../../control-plane' PNPM_COMMAND='pnpm --dir ../../web'
# Not COMPOSER: Composer reads an environment variable of that name as the
# name of its composer.json, and make exports what its command line sets.
COMPOSE := docker compose -f ../development/compose.yaml
COMPOSER_COMMAND ?= $(COMPOSE) exec -T php composer
PNPM_COMMAND ?= $(COMPOSE) exec -T node pnpm
HYGIENE := $(UV_RUN) python -m hostmesh_hygiene

# --recursive: at the workspace root alone, pnpm lists nothing with --prod.
collect:
	mkdir -p .inputs
	$(COMPOSER_COMMAND) licenses --format=json > .inputs/composer.json
	$(COMPOSER_COMMAND) licenses --no-dev --format=json > .inputs/composer-prod.json
	$(PNPM_COMMAND) --recursive licenses list --json > .inputs/pnpm.json
	$(PNPM_COMMAND) --recursive licenses list --json --prod > .inputs/pnpm-prod.json
	uv export --locked --no-dev --no-emit-project --no-hashes --directory ../../agent > .inputs/python-prod.txt

# Needs the dependencies of every project installed: the development stack
# (`make -C infrastructure/development up install`), and `make install` in
# agent/, infrastructure/testing/ and here.
licenses: collect
	$(HYGIENE) licenses

# Rewrites THIRD_PARTY_NOTICES at the repository root. Run it after a
# production dependency was added, removed or changed its license.
notices: collect
	$(HYGIENE) notices

notices-check: collect
	$(HYGIENE) notices --check

# Needs nothing but git and this project's environment.
spdx:
	$(HYGIENE) spdx
