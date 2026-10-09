# Test-node proof of concept

Evidence for [decision 0015](../../../docs/decisions/0015-debian-test-harness.md) (issue #16). It is **not** the test harness: issue #17 builds that, and may replace everything here.

## What it does

`./nodes.sh up` creates two Debian 13 nodes on [Incus](https://linuxcontainers.org/incus/), `hostmesh-node-1` and `hostmesh-node-2`, each with 2 GiB of RAM and 2 vCPUs, and snapshots them as `clean`. Run again, it restores both to that snapshot. Either way it ends by running `./verify.sh`, which checks on each node that it is clean, that PID 1 is systemd and the system state is `running`, that it is Debian 13 on cgroups v2, and that a transient unit runs in `hostmesh-poc.slice` with a private `/tmp`.

| Command | Effect |
|---------|--------|
| `./nodes.sh up` | Create or reset both nodes, then verify |
| `HOSTMESH_NODE_TYPE=vm ./nodes.sh up` | The same with virtual machines instead of system containers |
| `./nodes.sh status` | List the nodes |
| `./nodes.sh destroy` | Delete both nodes |
| `incus exec hostmesh-node-1 -- bash -s < probe.sh` | See "The probe" below |

`destroy` keeps the downloaded image (`incus image list` shows it as `hostmesh-poc-<type>-<image>`). It is not refreshed: `incus image delete <alias>` makes the next `up` download the current build.

`verify.sh` leaves a marker on each node, so running it twice without `nodes.sh up` in between fails its first check. That is deliberate: it shows that the reset works.

## Host setup (Fedora 44)

    sudo dnf install -y incus incus-agent
    echo "root:1000000:1000000000" | sudo tee -a /etc/subuid /etc/subgid
    sudo systemctl enable --now incus.socket
    sudo usermod -aG incus-admin "$USER"
    sudo incus admin init --minimal
    sudo firewall-cmd --zone=trusted --change-interface=incusbr0 --permanent
    sudo firewall-cmd --reload

The two `firewall-cmd` lines are needed where firewalld is active: without them the Incus bridge falls into the default zone, and a node gets no IPv4 address and cannot resolve names. Docker on the same host needed no extra rule.

Membership of `incus-admin` is equivalent to root on the host. Log out and in for the group to apply, or run a command at once with `sg incus-admin -c '<command>'`. Virtual machines need `/dev/kvm`.

## The probe

`probe.sh` runs inside a node and prints one `PASS` or `FAIL` line for each thing later slices need there: cgroup limits on a slice and that they hold, cgroup delegation, nftables, Nginx, PHP-FPM, MariaDB, PowerDNS and filesystem user quotas. It installs packages and changes the node, so reset afterwards with `./nodes.sh up`. Its results for containers and virtual machines are in the decision record.
