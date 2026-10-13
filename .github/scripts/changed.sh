#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Does a pull request touch what a workflow covers? (issue #105, decision 0017)
#
#   git diff --name-only HEAD^1 HEAD | changed.sh "web/**" "contracts/api/**" ".github/workflows/web.yml"
#
# Reads file names from standard input, one per line, and prints `true` when
# at least one matches a pattern, `false` otherwise. A pattern is a file name,
# or a directory followed by `/**` for everything below it. Nothing else is
# understood, and anything else is refused: a pattern that silently matched
# nothing would skip a suite for good.
set -euo pipefail

if [ "$#" -eq 0 ]; then
    echo "changed.sh: no pattern given" >&2
    exit 2
fi

for pattern in "$@"; do
    stem=${pattern%/\*\*}
    case "$stem" in
        "" | *[*?[]*)
            echo "changed.sh: unsupported pattern '$pattern': use a file name or 'directory/**'" >&2
            exit 2
            ;;
    esac
done

while IFS= read -r file; do
    [ -n "$file" ] || continue
    for pattern in "$@"; do
        if [ "$pattern" = "$file" ]; then
            echo true
            exit 0
        fi
        stem=${pattern%/\*\*}
        if [ "$stem" != "$pattern" ] && [ "${file#"$stem"/}" != "$file" ]; then
            echo true
            exit 0
        fi
    done
done

echo false
