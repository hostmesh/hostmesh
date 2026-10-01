#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Prints the hosts entries for the local development hostnames.
# With --apply, appends them to /etc/hosts (asks for sudo). Safe to run twice.

set -euo pipefail

HOSTS_FILE="${HOSTS_FILE:-/etc/hosts}"
BEGIN_MARKER='# BEGIN hostmesh development'
END_MARKER='# END hostmesh development'

entries() {
    printf '%s\n' \
        "$BEGIN_MARKER" \
        '127.0.0.1 app.hostmesh.test admin.hostmesh.test api.hostmesh.test agent.hostmesh.test' \
        "$END_MARKER"
}

case "${1:-}" in
    "")
        entries
        ;;
    --apply)
        if grep -qF "$BEGIN_MARKER" "$HOSTS_FILE"; then
            echo "Entries already present in ${HOSTS_FILE}"
            exit 0
        fi
        # The leading blank line keeps the block off a last line that has
        # no trailing newline.
        if [ -w "$HOSTS_FILE" ]; then
            { echo; entries; } >> "$HOSTS_FILE"
        else
            { echo; entries; } | sudo tee -a "$HOSTS_FILE" > /dev/null
        fi
        echo "Added entries to ${HOSTS_FILE}"
        ;;
    *)
        echo "Usage: $0 [--apply]" >&2
        exit 2
        ;;
esac
