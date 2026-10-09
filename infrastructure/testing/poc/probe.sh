#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Proof of concept for decision 0015 (issue #16). Runs as root INSIDE a
# test node and reports whether each thing later slices need works there
# (spec sections 13 and 14): cgroup limits on a slice that hold, cgroup
# delegation, nftables rules by UID, the four services, and filesystem
# user quotas.
#
# It installs packages and changes the node: reset the node afterwards.
# A FAIL is a result, not an error: the script always runs every probe
# and exits 0.

set -u

export DEBIAN_FRONTEND=noninteractive

passed=0
total=0

pass() {
    printf 'PASS  %s\n' "$1"
    passed=$((passed + 1))
    total=$((total + 1))
}

fail() {
    printf 'FAIL  %s\n      %s\n' "$1" "$2"
    total=$((total + 1))
}

# Installs packages quietly. Prints the tail of apt's output on failure.
install() {
    local log
    log=$(mktemp)
    if apt-get install -y --no-install-recommends "$@" >"$log" 2>&1; then
        rm -f "$log"
        return 0
    fi
    tail -n 5 "$log"
    rm -f "$log"
    return 1
}

service_probe() {
    local name=$1 unit=$2
    shift 2
    local out
    if ! out=$(install "$@"); then
        fail "$name" "install failed: $out"
        return
    fi
    if systemctl is-active --quiet "$unit"; then
        pass "$name"
    else
        fail "$name" "$unit is $(systemctl is-active "$unit"): $(systemctl status "$unit" --no-pager -n 3 2>&1 | tail -n 3 | paste -sd' ')"
    fi
}

SLICE=hostmesh-poc.slice
SLICE_CGROUP=/sys/fs/cgroup/hostmesh.slice/hostmesh-poc.slice

# The number after a key in one of the slice's cgroup event files.
slice_event() {
    awk -v key="$2" '$1 == key { print $2 }' "$SLICE_CGROUP/$1" 2>/dev/null
}

probe_slice_limits() {
    # Spec section 13: an account slice carries the memory and task
    # limits, and every unit of the account runs inside it. The limits
    # are set on the slice itself, as the agent will set them.
    local out
    systemctl start "$SLICE"
    systemctl set-property --runtime "$SLICE" MemoryMax=64M MemorySwapMax=0 TasksMax=16
    out=$(cat "$SLICE_CGROUP/memory.max" "$SLICE_CGROUP/pids.max" 2>&1 | paste -sd' ')
    if [ "$out" = "67108864 16" ]; then
        pass slice-limits
    else
        fail slice-limits "memory.max and pids.max of the slice are '${out}', expected '67108864 16'"
    fi
}

probe_tasks_limit_holds() {
    # A unit in the slice that starts 40 processes must be refused some.
    # shellcheck disable=SC2016 # expands inside the unit, not here
    systemd-run --quiet --wait --collect -p Slice="$SLICE" \
        sh -c 'for i in $(seq 1 40); do sleep 2 & done; wait' >/dev/null 2>&1
    if [ "$(slice_event pids.events max)" -gt 0 ] 2>/dev/null; then
        pass tasks-limit-holds
    else
        fail tasks-limit-holds "40 processes ran under TasksMax=16: pids.events max is '$(slice_event pids.events max)'"
    fi
}

probe_memory_limit_holds() {
    # A unit in the slice that holds 200 MB must be killed: `tail` keeps
    # a line without an end in memory.
    systemd-run --quiet --wait --collect -p Slice="$SLICE" \
        sh -c 'head -c 200M /dev/zero | tail' >/dev/null 2>&1
    if [ "$(slice_event memory.events oom_kill)" -gt 0 ] 2>/dev/null; then
        pass memory-limit-holds
    else
        fail memory-limit-holds "200 MB were held under MemoryMax=64M: memory.events oom_kill is '$(slice_event memory.events oom_kill)'"
    fi
}

probe_delegation() {
    # cgroups v2 delegation: a unit with Delegate=yes owns its subtree,
    # sees the memory and pids controllers there and can make a cgroup
    # of its own. PHP-FPM masters and user sessions rely on it.
    local out
    # shellcheck disable=SC2016 # expands inside the unit, not here
    out=$(systemd-run --quiet --wait --pipe --collect -p Slice="$SLICE" -p Delegate=yes \
        sh -c 'cg=/sys/fs/cgroup$(cut -d: -f3 /proc/self/cgroup); cat "$cg/cgroup.controllers"; mkdir "$cg/hostmesh-poc-sub" && echo created' 2>&1 | paste -sd' ')
    case "$out" in
        *memory*pids*created | *pids*memory*created) pass delegation ;;
        *) fail delegation "a delegated unit reported '${out}'" ;;
    esac
}

probe_nftables() {
    # Spec section 13: outbound TCP port 25 is blocked for account UIDs.
    local out
    if ! out=$(install nftables); then
        fail nftables "install failed: $out"
        return
    fi
    if out=$(nft -f - 2>&1 <<'EOF'
table inet hostmesh_poc {
    chain output {
        type filter hook output priority 0; policy accept;
        meta skuid 65534 tcp dport 25 counter reject
    }
}
EOF
    ) && nft list table inet hostmesh_poc | grep -q 'skuid 65534'; then
        # The rule must act, not only load: as nobody, port 25 is
        # refused at once; as root it is not matched.
        if ! setpriv --reuid 65534 --regid 65534 --clear-groups \
            timeout 3 bash -c 'exec 3<>/dev/tcp/192.0.2.1/25' 2>/dev/null \
            && nft list table inet hostmesh_poc | grep -Eq 'counter packets [1-9]'; then
            pass nftables
        else
            fail nftables "the rule loaded but its counter did not move for UID 65534"
        fi
    else
        fail nftables "nft refused the ruleset: $out"
    fi
    nft delete table inet hostmesh_poc 2>/dev/null
}

probe_powerdns() {
    # Spec section 9: a DNS node runs authoritative PowerDNS. Debian's
    # package starts with no backend, and systemd-resolved holds port 53
    # on 127.0.0.53 and 127.0.0.54, so the probe gives it a SQLite
    # backend and the node's own address, then asks it for a zone.
    local out address database=/var/lib/powerdns/hostmesh-poc.sqlite3
    if ! out=$(install pdns-server pdns-backend-sqlite3 sqlite3 bind9-dnsutils); then
        fail powerdns "install failed: $out"
        return
    fi
    address=$(hostname -I | awk '{print $1}')
    sqlite3 "$database" </usr/share/pdns-backend-sqlite3/schema/schema.sqlite3.sql
    printf 'launch=gsqlite3\ngsqlite3-database=%s\nlocal-address=%s\n' "$database" "$address" \
        >/etc/powerdns/pdns.d/hostmesh-poc.conf
    # Before the start: a running server only learns of a new zone when
    # its zone cache is refreshed.
    pdnsutil create-zone hostmesh-poc.test ns1.hostmesh-poc.test >/dev/null 2>&1
    chown pdns:pdns /var/lib/powerdns/hostmesh-poc.sqlite3*
    systemctl restart pdns.service
    if ! systemctl is-active --quiet pdns.service; then
        fail powerdns "pdns.service is $(systemctl is-active pdns.service): $(journalctl -u pdns.service -n 3 --no-pager -o cat | paste -sd' ')"
        return
    fi
    out=$(dig +short +norecurse "@$address" hostmesh-poc.test SOA 2>&1)
    case "$out" in
        *hostmaster.hostmesh-poc.test.*) pass powerdns ;;
        *) fail powerdns "it runs on $address but answered '${out}' for the zone's SOA" ;;
    esac
}

probe_quota() {
    # Spec section 13: filesystem user quotas, enforced hard. Uses a
    # loop-mounted ext4 with the quota feature, the way a second disk
    # would be set up on a node.
    local out image=/var/lib/hostmesh-poc-quota.img mount=/mnt/hostmesh-poc-quota
    if ! out=$(install quota e2fsprogs); then
        fail quota "install failed: $out"
        return
    fi
    mkdir -p "$mount"
    truncate -s 256M "$image"
    if ! out=$(mkfs.ext4 -q -O quota -E quotatype=usrquota "$image" 2>&1); then
        fail quota "mkfs.ext4 with the quota feature failed: $out"
        return
    fi
    if ! out=$(mount -o loop,usrquota "$image" "$mount" 2>&1); then
        fail quota "mounting a loop device failed: $out"
        return
    fi
    chmod 1777 "$mount"
    if ! out=$(setquota -u nobody 1024 1024 0 0 "$mount" 2>&1); then
        fail quota "setquota failed: $out"
    elif setpriv --reuid 65534 --regid 65534 --clear-groups \
        dd if=/dev/zero of="$mount/fill" bs=1M count=4 status=none 2>/dev/null; then
        fail quota "user nobody wrote 4 MiB past a 1 MiB hard limit"
    else
        pass quota
    fi
    umount "$mount" 2>/dev/null
}

apt-get update -qq >/dev/null 2>&1 || fail apt "apt-get update failed: the node has no network or no mirror"

probe_slice_limits
probe_tasks_limit_holds
probe_memory_limit_holds
probe_delegation
probe_nftables
service_probe nginx nginx.service nginx
service_probe php-fpm php8.4-fpm.service php8.4-fpm
service_probe mariadb mariadb.service mariadb-server
probe_powerdns
probe_quota

printf 'PROBE %d/%d\n' "$passed" "$total"
