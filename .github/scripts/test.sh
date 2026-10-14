#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Tests of changed.sh and gate.sh, the two scripts that let a workflow report
# on every pull request and still run its suite only when it has to (issue
# #105, decision 0017), and of service-images.sh (issue #181). Run by the
# Hygiene workflow: `.github/scripts/test.sh`. Needs jq and yq 4.
set -u

here=$(cd "$(dirname "$0")" && pwd)
failures=0

# check NAME EXPECTED_STATUS EXPECTED_OUTPUT COMMAND...   (stdin goes to the command)
check() {
    local name=$1 expected_status=$2 expected_output=$3
    shift 3
    local output status
    output=$("$@" 2>/dev/null)
    status=$?
    if [ "$status" -ne "$expected_status" ] || [ "$output" != "$expected_output" ]; then
        echo "FAIL: $name: expected status $expected_status and '$expected_output', got status $status and '$output'" >&2
        failures=$((failures + 1))
    else
        echo "ok: $name"
    fi
}

changed() { "$here/changed.sh" "$@"; }

# changed.sh: file names on stdin, patterns as arguments, `true` or `false` out.
check "a file under a directory pattern" 0 true \
    changed "control-plane/**" <<< "control-plane/app/Foo.php"
check "a file deep under a directory pattern" 0 true \
    changed "web/**" <<< "web/apps/admin/src/views/branding/BrandingForm.vue"
check "a file that only shares the prefix of a directory" 0 false \
    changed "web/**" <<< "website/index.html"
check "the directory name inside another path" 0 false \
    changed "agent/**" <<< "contracts/agent/schema.json"
check "an exact file" 0 true \
    changed ".github/workflows/web.yml" <<< ".github/workflows/web.yml"
check "a file next to an exact file" 0 false \
    changed "web/package.json" <<< "web/package.json.bak"
check "one match among several files and patterns" 0 true \
    changed "agent/**" "infrastructure/testing/**" <<< $'README.md\ndocs/spec/HostMesh_v0.1.md\ninfrastructure/testing/Makefile'
check "no match among several files and patterns" 0 false \
    changed "agent/**" "infrastructure/testing/**" <<< $'README.md\ndocs/decisions/0017-required-status-checks.md'
check "no file at all" 0 false \
    changed "agent/**" <<< ""
check "a file name with a space" 0 true \
    changed "docs/**" <<< "docs/a file.md"
check "a pattern with a wildcard it does not support" 2 "" \
    changed "web/*.json" <<< "web/package.json"
check "a wildcard in the middle of a pattern" 2 "" \
    changed "web/**/dist" <<< "web/apps/admin/dist"
check "no pattern" 2 "" \
    changed <<< "README.md"

gate() { NEEDS=$1 "$here/gate.sh"; }

needs() { # needs CHANGES_RESULT RUN [JOB=RESULT]...
    local json
    json=$(jq -n --arg result "$1" --arg run "$2" '{changes: {result: $result, outputs: {run: $run}}}')
    shift 2
    local pair
    for pair in "$@"; do
        json=$(jq --arg job "${pair%%=*}" --arg result "${pair#*=}" '.[$job] = {result: $result, outputs: {}}' <<< "$json")
    done
    printf '%s' "$json"
}

# gate.sh: the `needs` context as JSON in NEEDS; exit 0 only when the result
# is the one the change detection asked for.
check "the suite ran and passed" 0 "" \
    gate "$(needs success true checks=success)"
check "nothing changed and the suite was skipped" 0 "" \
    gate "$(needs success false checks=skipped)"
check "the suite failed" 1 "" \
    gate "$(needs success true checks=failure)"
check "the suite was cancelled" 1 "" \
    gate "$(needs success true checks=cancelled)"
check "one of several jobs failed" 1 "" \
    gate "$(needs success true harness=success containers=failure doubles=success)"
check "several jobs passed" 0 "" \
    gate "$(needs success true harness=success containers=success doubles=success)"
check "several jobs skipped because nothing changed" 0 "" \
    gate "$(needs success false harness=skipped containers=skipped doubles=skipped)"
# The case a required suite job alone would wave through.
check "the change detection failed, so the suite was skipped" 1 "" \
    gate "$(needs failure "" checks=skipped)"
check "the change detection was cancelled" 1 "" \
    gate "$(needs cancelled "" checks=skipped)"
check "the suite was skipped although something changed" 1 "" \
    gate "$(needs success true checks=skipped)"
check "the suite ran although nothing changed" 1 "" \
    gate "$(needs success false checks=success)"
check "the change detection gave no answer" 1 "" \
    gate "$(needs success "" checks=skipped)"
check "no suite job to judge" 1 "" \
    gate "$(needs success true)"
check "NEEDS is not JSON" 1 "" \
    gate "not json"

images() { "$here/service-images.sh" "$@"; }

fixtures=$(mktemp -d)
trap 'rm -rf "$fixtures"' EXIT
workflow=$fixtures/workflow.yml
compose=$fixtures/compose.yaml

cat > "$compose" <<'EOF'
services:
  postgres:
    image: postgres:18.6-trixie
  redis:
    image: redis:8.10.2-trixie
  php:
    build:
      context: ./php
EOF

# write_workflow POSTGRES_IMAGE REDIS_IMAGE: one job with two service containers.
write_workflow() {
    cat > "$workflow" <<EOF
jobs:
  checks:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: $1
        ports:
          - 5432:5432
      redis:
        image: $2
    steps:
      - run: true
EOF
}

# service-images.sh: the service containers of a workflow must have the
# images of the services with the same names in a compose file.
write_workflow postgres:18.6-trixie redis:8.10.2-trixie
check "every service container has the image of compose.yaml" 0 \
    $'postgres: postgres:18.6-trixie\nredis: redis:8.10.2-trixie' \
    images "$workflow" "$compose"

write_workflow postgres:18.6-trixie redis:8.10.3-trixie
check "a service container with another tag" 1 \
    $'postgres: postgres:18.6-trixie\n'"redis: redis:8.10.3-trixie in $workflow, redis:8.10.2-trixie in $compose" \
    images "$workflow" "$compose"

write_workflow postgres:18.7-trixie redis:8.10.3-trixie
check "two service containers with other tags" 1 \
    "postgres: postgres:18.7-trixie in $workflow, postgres:18.6-trixie in $compose"$'\n'"redis: redis:8.10.3-trixie in $workflow, redis:8.10.2-trixie in $compose" \
    images "$workflow" "$compose"

write_workflow postgres:18.6 redis:8.10.2-trixie
check "the same version with another suffix" 1 \
    "postgres: postgres:18.6 in $workflow, postgres:18.6-trixie in $compose"$'\nredis: redis:8.10.2-trixie' \
    images "$workflow" "$compose"

write_workflow ghcr.io/example/postgres:18.6-trixie redis:8.10.2-trixie
check "the same tag from another registry" 1 \
    "postgres: ghcr.io/example/postgres:18.6-trixie in $workflow, postgres:18.6-trixie in $compose"$'\nredis: redis:8.10.2-trixie' \
    images "$workflow" "$compose"

cat > "$workflow" <<'EOF'
jobs:
  checks:
    services:
      mariadb:
        image: mariadb:12.0
      php:
        image: php:8.5.11-fpm-trixie
EOF
check "a service container that compose.yaml does not have, or builds" 1 \
    "mariadb: mariadb:12.0 in $workflow, no such service in $compose"$'\n'"php: php:8.5.11-fpm-trixie in $workflow, no image in $compose" \
    images "$workflow" "$compose"

cat > "$workflow" <<'EOF'
jobs:
  unit:
    services:
      postgres:
        image: postgres:18.6-trixie
  feature:
    services:
      redis:
        image: redis:8.10.1-trixie
EOF
check "service containers of several jobs" 1 \
    $'postgres: postgres:18.6-trixie\n'"redis: redis:8.10.1-trixie in $workflow, redis:8.10.2-trixie in $compose" \
    images "$workflow" "$compose"

# A check that compares nothing would pass for good.
cat > "$workflow" <<'EOF'
jobs:
  checks:
    steps:
      - run: true
EOF
check "a workflow without service containers" 2 "" \
    images "$workflow" "$compose"
check "a workflow that does not exist" 2 "" \
    images "$fixtures/missing.yml" "$compose"
write_workflow postgres:18.6-trixie redis:8.10.2-trixie
check "a compose file that does not exist" 2 "" \
    images "$workflow" "$fixtures/missing.yaml"
check "no arguments" 2 "" \
    images
printf 'jobs: [unclosed\n' > "$fixtures/broken.yaml"
check "a workflow that is not YAML" 2 "" \
    images "$fixtures/broken.yaml" "$compose"
check "a compose file that is not YAML" 2 "" \
    images "$workflow" "$fixtures/broken.yaml"

if [ "$failures" -gt 0 ]; then
    echo "$failures failed" >&2
    exit 1
fi
echo "all passed"
