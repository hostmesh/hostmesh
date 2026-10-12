# SPDX-License-Identifier: Apache-2.0
#
# Local development stack. Run from the repository root with
# `make -C infrastructure/development <target>`.

.PHONY: up doubles down reset install smoke hosts trust

# The php service runs as the host user (see compose.yaml).
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

# Caddy reads its Caddyfile only when it starts, and `up` leaves a running
# container alone: reload it, so that a changed Caddyfile takes effect.
up: .env
	docker compose up --detach --wait --build
	docker compose exec -T caddy caddy reload --config /etc/caddy/Caddyfile

# Only the ACME and DNS test doubles, for `make -C agent test-doubles`.
doubles: .env
	docker compose up --detach --wait pebble challtestsrv

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
	docker compose restart node
	docker compose exec -T node sh -c 'for i in $$(seq 1 60); do node -e "Promise.all([5173, 5174].map((port) => fetch(\"http://127.0.0.1:\" + port + \"/\"))).then(() => process.exit(0), () => process.exit(1))" && exit 0; sleep 1; done; echo "The Vite dev servers did not start. See: docker compose logs node" >&2; exit 1'

smoke:
	./smoke.sh

hosts:
	./hosts.sh --apply

trust:
	./trust.sh --apply

.env:
	cp .env.example .env
