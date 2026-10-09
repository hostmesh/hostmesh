#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Proof of concept for decision 0015 (issue #16). Checks, from the host,
# that both test nodes are clean, run Debian 13 under systemd, report
# cgroups v2 and can run a transient unit in a slice with a private /tmp.
# Runs every check and exits non-zero if any of them failed.
#
# The last step leaves a marker on each node, so a second run without a
# reset fails its first check: that is how a reset is shown to work.

set -u

NODES=(hostmesh-node-1 hostmesh-node-2)
MARKER=/var/lib/hostmesh-poc-dirty

failures=0

pass() {
    printf 'PASS  %s\n' "$1"
}

fail() {
    printf 'FAIL  %s\n      %s\n' "$1" "$2"
    failures=$((failures + 1))
}

# Runs a command on a node as root and prints its output without the
# trailing newline. Empty when the node cannot be reached.
on() {
    local node=$1
    shift
    incus exec "$node" -- "$@" 2>/dev/null
}

check_node() {
    local node=$1 value units

    if on "$node" test -e "$MARKER"; then
        fail "$node: is clean" "$MARKER exists: the node was used and not reset"
    else
        pass "$node: is clean"
    fi

    value=$(on "$node" cat /proc/1/comm)
    if [ "$value" = systemd ]; then
        pass "$node: PID 1 is systemd"
    else
        fail "$node: PID 1 is systemd" "got '${value}'"
    fi

    # `degraded` is a failure here: which units fail in a container is
    # what the decision record needs to know.
    value=$(on "$node" systemctl is-system-running --wait)
    if [ "$value" = running ]; then
        pass "$node: systemd reports running"
    else
        units=$(on "$node" systemctl list-units --failed --plain --no-legend | awk '{print $1}' | paste -sd' ')
        fail "$node: systemd reports running" "got '${value}', failed units: ${units:-none}"
    fi

    # shellcheck disable=SC2016 # expands on the node, not here
    value=$(on "$node" sh -c '. /etc/os-release && printf %s "$VERSION_ID"')
    if [ "$value" = 13 ]; then
        pass "$node: Debian 13"
    else
        fail "$node: Debian 13" "VERSION_ID is '${value}'"
    fi

    value=$(on "$node" stat -fc %T /sys/fs/cgroup)
    if [ "$value" = cgroup2fs ]; then
        pass "$node: cgroups v2"
    else
        fail "$node: cgroups v2" "/sys/fs/cgroup is '${value}'"
    fi

    # A unit named hostmesh-poc.slice lives under hostmesh.slice: systemd
    # reads the dash as a level of the hierarchy (spec section 13).
    value=$(on "$node" systemd-run --quiet --wait --pipe --collect \
        -p Slice=hostmesh-poc.slice -p PrivateTmp=yes cat /proc/self/cgroup)
    case "$value" in
        0::/hostmesh.slice/hostmesh-poc.slice/run-*.service)
            pass "$node: transient unit runs in its slice"
            ;;
        *)
            fail "$node: transient unit runs in its slice" "cgroup is '${value}'"
            ;;
    esac

    on "$node" touch /tmp/hostmesh-poc-host-file
    if on "$node" systemd-run --quiet --wait --pipe --collect \
        -p Slice=hostmesh-poc.slice -p PrivateTmp=yes test ! -e /tmp/hostmesh-poc-host-file; then
        pass "$node: transient unit has a private /tmp"
    else
        fail "$node: transient unit has a private /tmp" "the unit sees a file of the node's /tmp, or did not run"
    fi

    on "$node" touch "$MARKER"
}

for node in "${NODES[@]}"; do
    check_node "$node"
done

if [ "$failures" -gt 0 ]; then
    printf '\n%d check(s) failed.\n' "$failures"
    exit 1
fi

printf '\nAll checks passed on %d nodes.\n' "${#NODES[@]}"
