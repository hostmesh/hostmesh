# SPDX-License-Identifier: Apache-2.0
#
# Local development stack. Run from the repository root with
# `make -C infrastructure/development <target>`.

.PHONY: up doubles e2e-up e2e down reset install smoke hosts trust

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

E2E := docker compose -f compose.yaml -f compose.e2e.yaml
# Where Chromium reads trusted authorities (found in the spike of issue #32):
# this directory wins whenever it exists; .local/share/pki/nssdb is only
# the fallback.
NSS_DB := .pki/nssdb

# Switches the stack to end-to-end mode: the built apps behind Caddy with
# the production headers, and the control plane on its own database.
# Needs `make up install` once before. `make up` switches back.
# The apps are built before Caddy mounts them: Docker would create a
# missing dist/ as an empty directory owned by root.
e2e-up: .env
	docker compose up --detach --wait --build node postgres
	docker compose exec -T node pnpm build
	test -f ../../web/apps/admin/dist/index.html && test -f ../../web/apps/portal/dist/index.html \
		|| { echo "The apps were not built. See the output of pnpm build above." >&2; exit 1; }
	docker compose exec -T postgres sh -c 'psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -tAc "SELECT 1 FROM pg_database WHERE datname = '"'"'hostmesh_e2e'"'"'" | grep -q 1 || createdb -U "$$POSTGRES_USER" hostmesh_e2e'
	$(E2E) up --detach --wait --build
	$(E2E) exec -T caddy caddy reload --config /etc/caddy/Caddyfile
	$(E2E) exec -T php php artisan migrate --force
	$(E2E) exec -T caddy cat /data/caddy/pki/authorities/local/root.crt \
		| $(E2E) exec -T node sh -c 'cat > /tmp/hostmesh-ca.crt \
			&& rm -rf "$$HOME/$(NSS_DB)" && mkdir -p "$$HOME/$(NSS_DB)" \
			&& certutil -d "sql:$$HOME/$(NSS_DB)" -N --empty-password \
			&& certutil -d "sql:$$HOME/$(NSS_DB)" -A -t "C,," -n "Hostmesh development CA" -i /tmp/hostmesh-ca.crt'

# Runs the Playwright suite (web/e2e) against the stack in end-to-end mode.
# Every run empties the end-to-end cache (the login throttles), seeds fresh
# accounts and writes their credentials to web/e2e/.seed.json, which git
# ignores. Pass Playwright arguments with ARGS, for example
# `make e2e ARGS="tests/admin-login.spec.ts --debug"`.
e2e: .env
	@$(E2E) exec -T php printenv APP_ENV 2>/dev/null | grep -qx e2e \
		|| { echo "The stack is not in end-to-end mode. Run: make e2e-up" >&2; exit 1; }
	$(E2E) exec -T php php artisan cache:clear
	$(E2E) exec -T php php artisan e2e:seed > ../../web/e2e/.seed.json.tmp
	mv ../../web/e2e/.seed.json.tmp ../../web/e2e/.seed.json
	$(E2E) exec -T -e CI -e NODE_EXTRA_CA_CERTS=/tmp/hostmesh-ca.crt node pnpm e2e $(ARGS)

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
