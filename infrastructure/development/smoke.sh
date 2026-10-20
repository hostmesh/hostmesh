#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Smoke test for the local development stack. Checks PostgreSQL, Redis, each
# hostname over HTTPS, the API's health endpoint, that the worker runs a
# dispatched job, the versions of the node service, its pnpm store and its
# Chromium build, and the ACME and DNS test doubles, using the system
# resolver and trust store.
# Runs every check and exits non-zero if any of them failed.

set -u

cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1

HOSTNAMES=(
    app.hostmesh.test
    admin.hostmesh.test
    agent.hostmesh.test
)

failures=0

pass() {
    printf 'PASS  %s\n' "$1"
}

fail() {
    printf 'FAIL  %s\n      %s\n' "$1" "$2"
    failures=$((failures + 1))
}

# Connects to the service name rather than 127.0.0.1: inside the container
# loopback connections skip password authentication, the network address
# does not. This is the path the application containers will use.
check_postgres() {
    local name="PostgreSQL accepts a password login and answers a query"
    local out
    if out=$(docker compose exec -T postgres sh -c \
        'PGPASSWORD="$POSTGRES_PASSWORD" psql -h postgres -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT 1"' 2>&1) \
        && [ "$out" = "1" ]; then
        pass "$name"
    else
        fail "$name" "Is the stack up? Run: make up"
    fi
}

check_redis() {
    local name="Redis answers PING"
    local out
    if out=$(docker compose exec -T redis redis-cli -h redis ping 2>&1) \
        && [ "$out" = "PONG" ]; then
        pass "$name"
    else
        fail "$name" "Is the stack up? Run: make up"
    fi
}

check_https() {
    local name="https://$1/ responds"
    local status=0
    curl --silent --fail --max-time 10 --output /dev/null "https://$1/" || status=$?
    case "$status" in
        0) pass "$name" ;;
        6) fail "$name" "Name does not resolve. Run: make hosts" ;;
        7) fail "$name" "Connection refused. Is the stack up? Run: make up" ;;
        60) fail "$name" "Certificate is not trusted. Run: make trust" ;;
        22) fail "$name" "The server answered an error. For app. and admin.: run make install (it starts the Vite dev servers)" ;;
        *) fail "$name" "curl exit code ${status}" ;;
    esac
}

check_api_health() {
    local name="https://api.hostmesh.test/api/v1/health reports ok"
    local out
    if out=$(curl --silent --fail --max-time 10 "https://api.hostmesh.test/api/v1/health" 2>&1) \
        && [ "$out" = '{"status":"ok"}' ]; then
        pass "$name"
    else
        fail "$name" "Is the control plane installed? Run: make install"
    fi
}

# Queued jobs run in the worker service (spec section 24: Laravel queues on
# Redis). Without it a dispatched job stays `pending` in job_history.
# A running listener is not enough: one on the wrong connection or queue
# looked healthy (issue #156). The control plane's `smoke:queue` dispatches a
# job the way the application does and waits until a worker has run it. The
# job writes nothing to job_history, only a marker in the cache that the
# command removes.
check_worker() {
    local name="The worker consumes a job dispatched to the application's queue within 15 s"
    if ! docker compose ps --status running --services 2>/dev/null | grep -qx worker; then
        fail "$name" "Is the stack up? Run: make up"
    elif docker compose exec -T php php artisan smoke:queue --timeout=15 >/dev/null 2>&1; then
        pass "$name"
    else
        fail "$name" "No worker ran the job. Run: make install, then: docker compose logs worker"
    fi
}

# The node service must run the versions the web workspace pins: Node from
# web/.nvmrc and pnpm from the packageManager field of web/package.json.
# pnpm is asked from / so that it reports the version built into the image
# rather than one it switched to for the workspace.
check_node() {
    local name="The node service runs the Node and pnpm versions the web workspace pins"
    local want_node want_pnpm got_node got_pnpm
    want_node=$(cat ../../web/.nvmrc 2>/dev/null | tr -d '[:space:]')
    want_pnpm=$(sed -n 's/.*"packageManager": *"pnpm@\([^"+]*\).*/\1/p' ../../web/package.json 2>/dev/null)
    got_node=$(docker compose exec -T node node --version 2>/dev/null)
    got_pnpm=$(docker compose exec -T -w / node pnpm --version 2>/dev/null)
    if [ -n "$want_node" ] && [ -n "$want_pnpm" ] \
        && [ "$got_node" = "v$want_node" ] && [ "$got_pnpm" = "$want_pnpm" ]; then
        pass "$name"
    else
        fail "$name" "Wanted Node ${want_node:-?} and pnpm ${want_pnpm:-?}, got ${got_node:-none} and ${got_pnpm:-none}. Run: make up"
    fi
}

# pnpm keeps one store, in web/node_modules/.pnpm-store. Left to itself,
# pnpm picks the store by working directory: from web/packages/ui, where the
# shadcn-vue CLI runs, it used a second one in web/.pnpm-store (issue #146).
check_pnpm_store() {
    local name="pnpm uses web/node_modules/.pnpm-store from every directory of the workspace"
    local want=/workspace/web/node_modules/.pnpm-store dir got bad=""
    for dir in /workspace/web /workspace/web/packages/ui; do
        got=$(docker compose exec -T -w "$dir" node pnpm store path 2>/dev/null)
        case "$got" in
            "$want"/*) ;;
            *) bad="$bad ${dir#/workspace/}: ${got:-none};" ;;
        esac
    done
    if [ -z "$bad" ]; then
        pass "$name"
    else
        fail "$name" "Wanted a store under $want, got${bad%;}. Run: make up"
    fi
}

# The component tests of @hostmesh/ui run in the Chromium build that one
# Playwright release drives. The image installs it for PLAYWRIGHT_VERSION,
# which must equal the playwright entry of the catalog in
# web/pnpm-workspace.yaml.
check_playwright() {
    local name="The node service has Chromium for the Playwright version the web workspace pins"
    local want got_version got_browser
    want=$(sed -n 's/^  playwright: *//p' ../../web/pnpm-workspace.yaml 2>/dev/null | tr -d '[:space:]"')
    got_version=$(docker compose exec -T node printenv PLAYWRIGHT_VERSION 2>/dev/null | tr -d '[:space:]')
    got_browser=$(docker compose exec -T node sh -c 'ls -d "$PLAYWRIGHT_BROWSERS_PATH"/chromium_headless_shell-* 2>/dev/null | head -n 1' 2>/dev/null)
    if [ -n "$want" ] && [ "$got_version" = "$want" ] && [ -n "$got_browser" ]; then
        pass "$name"
    else
        fail "$name" "Wanted Playwright ${want:-?} with Chromium, got ${got_version:-none} and ${got_browser:-no browser}. Run: make up"
    fi
}

# Pebble is Let's Encrypt's ACME test server (spec section 37). Its API
# certificate comes from a CA inside its image, which is copied out for
# this one request and never trusted system-wide.
check_pebble() {
    local name="Pebble serves its ACME directory on https://localhost:14000/dir"
    local ca out
    ca=$(mktemp)
    if docker compose cp pebble:/test/certs/pebble.minica.pem "$ca" >/dev/null 2>&1 \
        && out=$(curl --silent --fail --max-time 10 --cacert "$ca" https://localhost:14000/dir 2>&1) \
        && printf '%s' "$out" | grep -q '"newOrder"'; then
        pass "$name"
    else
        fail "$name" "Is the stack up? Run: make up"
    fi
    rm -f "$ca"
}

# The challenge test server is a mock DNS server with an HTTP management
# API. The check adds a record and removes it again.
check_challtestsrv() {
    local name="The challenge test server's management API accepts a DNS record"
    if curl --silent --fail --max-time 10 --output /dev/null \
        --data '{"host":"smoke.hostmesh.test","addresses":["192.0.2.1"]}' http://localhost:8055/add-a \
        && curl --silent --fail --max-time 10 --output /dev/null \
            --data '{"host":"smoke.hostmesh.test"}' http://localhost:8055/clear-a; then
        pass "$name"
    else
        fail "$name" "Is the stack up? Run: make up"
    fi
}

# The control plane's tests run in the php service and reach the doubles
# by service name.
check_doubles_from_php() {
    local name="The php service reaches pebble:14000 and challtestsrv:8055"
    if docker compose exec -T php php -r \
        'exit(@fsockopen("pebble", 14000, $e, $s, 5) && @fsockopen("challtestsrv", 8055, $e, $s, 5) ? 0 : 1);' \
        >/dev/null 2>&1; then
        pass "$name"
    else
        fail "$name" "Is the stack up? Run: make up"
    fi
}

check_postgres
check_redis
for host in "${HOSTNAMES[@]}"; do
    check_https "$host"
done
check_api_health
check_worker
check_node
check_pnpm_store
check_playwright
check_pebble
check_challtestsrv
check_doubles_from_php

if [ "$failures" -gt 0 ]; then
    printf '\n%d check(s) failed\n' "$failures"
    exit 1
fi

printf '\nAll checks passed\n'
