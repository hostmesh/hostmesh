#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Smoke test for the local development stack. Checks PostgreSQL, Redis and
# each hostname over HTTPS, using the system resolver and trust store.
# Runs every check and exits non-zero if any of them failed.

set -u

cd "$(dirname "${BASH_SOURCE[0]}")" || exit 1

HOSTNAMES=(
    app.hostmesh.test
    admin.hostmesh.test
    api.hostmesh.test
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
        *) fail "$name" "curl exit code ${status}" ;;
    esac
}

check_postgres
check_redis
for host in "${HOSTNAMES[@]}"; do
    check_https "$host"
done

if [ "$failures" -gt 0 ]; then
    printf '\n%d check(s) failed\n' "$failures"
    exit 1
fi

printf '\nAll checks passed\n'
