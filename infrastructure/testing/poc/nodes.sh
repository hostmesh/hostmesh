#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Proof of concept for decision 0015 (issue #16): two disposable Debian 13
# nodes on Incus, created and reset by one command.
#
#   nodes.sh up        create the nodes, or reset them to their clean
#                      snapshot, then run verify.sh
#   nodes.sh destroy   delete both nodes
#   nodes.sh status    list them
#
# HOSTMESH_NODE_TYPE   container (default) or vm
# HOSTMESH_NODE_IMAGE  default images:debian/13
#
# This is not the test harness. Issue #17 builds that.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# `incus launch` and `incus snapshot create` read configuration from
# standard input when it is not a terminal, and wait for it. Under CI or
# a pipe that never ends: give every command an empty one.
exec </dev/null

NODES=(hostmesh-node-1 hostmesh-node-2)
TYPE=${HOSTMESH_NODE_TYPE:-container}
IMAGE=${HOSTMESH_NODE_IMAGE:-images:debian/13}
SNAPSHOT=clean
MEMORY=2GiB
CPUS=2
# Seconds to wait for a node to answer and for systemd to finish booting.
BOOT_TIMEOUT=180

die() {
    printf 'nodes.sh: %s\n' "$1" >&2
    exit 1
}

# Prints "TIME <phase> <seconds>" for the command it runs.
timed() {
    local phase=$1 start=$SECONDS
    shift
    "$@"
    printf 'TIME %s %d\n' "$phase" "$((SECONDS - start))"
}

exists() {
    incus info "$1" >/dev/null 2>&1
}

# Prints `container` or `vm` for an existing node.
type_of() {
    case "$(incus list "^$1\$" -f csv -c t)" in
        CONTAINER*) echo container ;;
        VIRTUAL-MACHINE*) echo vm ;;
        *) echo unknown ;;
    esac
}

state_of() {
    incus list "^$1\$" -f csv -c s
}

# Waits until commands run on the node (a VM's agent starts after its
# kernel) and systemd has finished booting, in whatever state.
wait_until_booted() {
    local node=$1 deadline=$((SECONDS + BOOT_TIMEOUT)) state
    while [ "$SECONDS" -lt "$deadline" ]; do
        state=$(incus exec "$node" -- systemctl is-system-running 2>/dev/null || true)
        case "$state" in
            running | degraded) return 0 ;;
        esac
        sleep 1
    done
    die "$node did not finish booting within ${BOOT_TIMEOUT}s (last state: '${state:-no answer}')"
}

launch_flags() {
    if [ "$TYPE" = vm ]; then
        echo --vm
    fi
}

fetch_image() {
    # Copies the image into the local store, so that `create` measures
    # the nodes and not the download. A no-op when it is already there.
    # shellcheck disable=SC2046
    incus image copy --quiet "$IMAGE" local: --alias "hostmesh-poc-$TYPE" $(launch_flags) --auto-update=false 2>/dev/null \
        || incus image info "hostmesh-poc-$TYPE" >/dev/null
}

create() {
    local node
    for node in "$@"; do
        # shellcheck disable=SC2046
        incus launch --quiet "hostmesh-poc-$TYPE" "$node" $(launch_flags) \
            -c limits.memory="$MEMORY" -c limits.cpu="$CPUS"
    done
    for node in "$@"; do
        wait_until_booted "$node"
        # The snapshot is taken with the node stopped: a restore then
        # starts from a clean boot and not from a frozen moment.
        incus stop "$node"
        incus snapshot create "$node" "$SNAPSHOT"
        incus start "$node"
    done
    for node in "$@"; do
        wait_until_booted "$node"
    done
}

reset() {
    local node
    for node in "$@"; do
        if [ "$(state_of "$node")" = RUNNING ]; then
            incus stop --force "$node"
        fi
        incus snapshot restore "$node" "$SNAPSHOT"
        incus start "$node"
    done
    for node in "$@"; do
        wait_until_booted "$node"
    done
}

up() {
    local node missing=() present=()

    case "$TYPE" in
        vm | container) ;;
        *) die "HOSTMESH_NODE_TYPE must be 'vm' or 'container', got '$TYPE'" ;;
    esac

    if [ "$TYPE" = vm ] && [ ! -w /dev/kvm ]; then
        die "/dev/kvm is missing or not writable: virtual machines need KVM. Use HOSTMESH_NODE_TYPE=container."
    fi

    for node in "${NODES[@]}"; do
        if ! exists "$node"; then
            missing+=("$node")
        elif [ "$(type_of "$node")" != "$TYPE" ]; then
            die "$node exists as a $(type_of "$node"), not a $TYPE. Run 'nodes.sh destroy' first."
        else
            present+=("$node")
        fi
    done

    if [ "${#missing[@]}" -gt 0 ]; then
        timed image fetch_image
        timed create create "${missing[@]}"
    fi

    if [ "${#present[@]}" -gt 0 ]; then
        timed reset reset "${present[@]}"
    fi

    timed verify ./verify.sh
}

destroy() {
    local node
    for node in "${NODES[@]}"; do
        if exists "$node"; then
            incus delete --force "$node"
        fi
    done
}

command -v incus >/dev/null || die "incus is not installed. See README.md in this directory."

case "${1:-}" in
    up) up ;;
    destroy) destroy ;;
    status) incus list '^hostmesh-node-[12]$' -c nstS4 ;;
    *) die "usage: nodes.sh up|destroy|status" ;;
esac
