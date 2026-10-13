#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# The verdict of a workflow that decides by itself whether its suite runs
# (issue #105, decision 0017). The job that calls this is the required status
# check: it runs always, and passes only when
#
#   - the `changes` job succeeded and answered `true`, and every other job
#     succeeded; or
#   - the `changes` job succeeded and answered `false`, and every other job
#     was skipped.
#
# GitHub counts a skipped job as passing, also when it was skipped because
# the job it needs failed. Requiring the suite's own job would therefore let
# a broken change detection wave a pull request through. This does not.
#
# NEEDS holds the `needs` context of the calling job: `${{ toJSON(needs) }}`.
set -euo pipefail

fail() {
    echo "gate.sh: $1" >&2
    exit 1
}

needs=${NEEDS:-}
jq -e 'type == "object"' <<< "$needs" > /dev/null 2>&1 || fail "NEEDS is not the JSON of a needs context"

detection=$(jq -r '.changes.result // "missing"' <<< "$needs")
[ "$detection" = success ] || fail "the change detection did not succeed: $detection"

run=$(jq -r '.changes.outputs.run // ""' <<< "$needs")
case "$run" in
    true) expected=success ;;
    false) expected=skipped ;;
    *) fail "the change detection answered '$run', not true or false" ;;
esac

jobs=$(jq -r 'del(.changes) | keys[]' <<< "$needs")
[ -n "$jobs" ] || fail "there is no job to judge"

verdict=0
while IFS= read -r job; do
    result=$(jq -r --arg job "$job" '.[$job].result' <<< "$needs")
    if [ "$result" = "$expected" ]; then
        echo "$job: $result" >&2
    else
        echo "gate.sh: $job: $result, expected $expected" >&2
        verdict=1
    fi
done <<< "$jobs"

exit "$verdict"
