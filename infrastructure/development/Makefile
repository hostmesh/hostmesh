# SPDX-License-Identifier: Apache-2.0
#
# Local development stack. Run from the repository root with
# `make -C infrastructure/development <target>`.

.PHONY: up down reset install smoke hosts trust

# The php service runs as the host user (see compose.yaml).
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

up: .env
		docker compose up --detach --wait --build

down: .env
		docker compose down

reset: .env
		docker compose down --volumes --remove-orphans

install:
		docker compose exec -T php composer install --no-interaction
		docker compose exec -T php sh -c 'test -f .env || cp .env.example .env'
		docker compose exec -T php sh -c 'grep -q "^APP_KEY=base64:" .env || php artisan key:generate --ansi'
		docker compose exec -T php php artisan migrate --force
		docker compose exec -T node pnpm install --frozen-lockfile

smoke:
		./smoke.sh

hosts:
		./hosts.sh --apply

trust:
		./trust.sh --apply

.env:
		cp .env.example .env
