#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Tests of changed.sh and gate.sh, the two scripts that let a workflow report
# on every pull request and still run its suite only when it has to (issue
# #105, decision 0017). Run by the Hygiene workflow: `.github/scripts/test.sh`.
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

if [ "$failures" -gt 0 ]; then
    echo "$failures failed" >&2
    exit 1
fi
echo "all passed"
