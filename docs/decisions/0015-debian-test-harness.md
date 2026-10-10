# 0015. Debian 13 test nodes on Incus: containers on every pull request, virtual machines for isolation

- Status: accepted
- Date: 2026-10-09
- Issue: Spike: Debian 13 test-node harness (#16)

## Context

The agent's integration tests run "against a real Debian 13 VM or system container with systemd and cgroups v2, exercising the real services", "at least nightly and before release, and per pull request where CI allows" (spec section 37). The isolation suite runs "on a real Debian VM". Two nodes are needed, because DNS tests compare two DNS nodes (sections 9 and 37).

From sections 13, 14 and 37, a test node must offer:

- systemd as PID 1 in the state `running`, on cgroups v2, with memory and task limits that hold on a slice, and cgroup delegation to a unit;
- nftables rules that match by UID (outbound TCP port 25 is blocked for account UIDs);
- Nginx, PHP-FPM, MariaDB and PowerDNS as ordinary Debian services;
- filesystem user quotas, enforced hard;
- a reset to a clean state that is quick enough to use between test groups.

The owner's constraints (2026-10-09): Linux is required now and macOS on Apple Silicon must not be ruled out; the repository becomes public, so standard hosted runners are free; each node gets 2 GiB of RAM and 2 vCPUs.

Checked on 2026-10-09:

- Debian 13 packages `php8.4-fpm` 8.4.24, `pdns-server` 4.9.17, `mariadb-server` 11.8.6, `nginx` 1.26.3 and `quota` 4.09 ([sources.debian.org](https://sources.debian.org/)).
- Incus: upstream 7.5.1, with 7.0 as the LTS line; Apache-2.0 ([releases](https://github.com/lxc/incus/releases)). Fedora 44 packages 6.23, Ubuntu 24.04 packages 6.0.0, Debian 13 packages 6.0.4. The Linux Containers project builds a Debian 13 image every day for amd64 and arm64, as a container root (105 MB) and as a virtual machine disk (365 MB) ([image server](https://images.linuxcontainers.org/)). Builds for macOS "include only the client, not the server" ([installing Incus](https://linuxcontainers.org/incus/docs/main/installing/)).
- Lima: 2.2.1, Apache-2.0, with a `debian-13` template; `limactl snapshot` is on its list of experimental commands ([releases](https://github.com/lima-vm/lima/releases), [experimental features](https://lima-vm.io/docs/releases/experimental/)).
- Vagrant: 2.4.9 of August 2025, under the Business Source License since 2.4.0; 2.3 was MIT ([license](https://github.com/hashicorp/vagrant/blob/main/LICENSE)). Fedora 44 and Debian 13 still package the 2.3 line of 2023; Ubuntu 24.04 has no package. The official box `debian/trixie64` exists for libvirt on amd64 only ([box](https://portal.cloud.hashicorp.com/vagrant/discover/debian/trixie64)). `vagrant-libvirt` had its last release in June 2023.
- VirtualBox: 7.2.20 ([changelog](https://www.virtualbox.org/wiki/Changelog-7.2)). There is no official Debian 13 box for it.
- GitHub-hosted runners: `ubuntu-24.04` has 4 vCPUs and 16 GB of RAM in public repositories, 2 vCPUs and 8 GB in private ones, and 14 GB of disk in both. Linux runners support hardware acceleration after a udev rule that opens `/dev/kvm`. Docker and Podman are preinstalled; Incus, QEMU and Vagrant are not. `ubuntu-latest` moves to Ubuntu 26.04 in November 2026 ([runner reference](https://docs.github.com/en/actions/reference/runners/github-hosted-runners), [KVM changelog](https://github.blog/changelog/2024-04-02-github-actions-hardware-accelerated-android-virtualization-now-available/), [image contents](https://github.com/actions/runner-images/blob/main/images/ubuntu/Ubuntu2404-Readme.md)).
- Standard runners are free for public repositories. A private repository has 2,000 included minutes a month on GitHub Free and 3,000 on Pro or Team ([billing](https://docs.github.com/en/billing/concepts/product-billing/github-actions)).

## Options considered

Three options were run: Incus system containers and Incus virtual machines on the owner's machine and on a hosted runner, and Lima on the owner's machine. The other five were compared from their documentation and are marked "not tested".

| Option | Debian 13 image | Kernel | systemd `running`, cgroups v2, slice limits, delegation | nftables by UID | Nginx, PHP-FPM, MariaDB, PowerDNS | User quotas | Reset | Hosted runner | macOS | License |
|---|---|---|---|---|---|---|---|---|---|---|
| Incus system container | daily, amd64 and arm64 | host's | yes | yes | yes | **no**: no loop devices | snapshot restore | install; no KVM needed | client only; server inside a Linux VM, not tested | Apache-2.0 |
| Incus virtual machine | daily, amd64 and arm64 | own | yes | yes | yes | yes | snapshot restore | install; needs KVM, which is there | as above, plus nested virtualization, not tested | Apache-2.0 |
| Lima | Debian's cloud image, amd64 and arm64 | own | yes; enforcement and delegation not run | yes | yes | yes | delete and start; snapshots experimental and failed here | tarball and QEMU, not tested | native | Apache-2.0 |
| Vagrant with libvirt | official box, amd64 only | own | not tested | not tested | not tested | not tested | `vagrant snapshot`, not tested | no Ubuntu 24.04 package | no | BUSL (2.4), MIT (2.3, 2023) |
| Vagrant with VirtualBox | community boxes only | own | not tested | not tested | not tested | not tested | `vagrant snapshot`, not tested | not tested | yes | BUSL; VirtualBox GPL |
| libvirt with Debian's cloud image | Debian's cloud image | own | not tested | not tested | not tested | not tested | qcow2 overlay, our own scripts | install; needs KVM | no | LGPL/GPL |
| Docker or Podman running systemd | none with systemd: build one | host's | not tested | not tested | not tested | not tested; needs a privileged container | remove and run | preinstalled | through a VM | Apache-2.0 |
| systemd-nspawn | none: build with debootstrap or mkosi | host's | not tested | not tested | not tested | not tested | ephemeral mode | install; root | no | LGPL |

Rejected:

- **Lima.** It did everything a virtual machine must do (it passed the seven probes that existed when it was run) and it is the only option that is native on macOS. But its snapshot command is experimental, and after `limactl snapshot apply` neither node answered within 30 seconds. The reset that works is delete and start: 25 seconds for two nodes, against 4 for Incus containers and 18 for Incus virtual machines. It has no containers, so it offers no fast tier. It stays the candidate for a macOS path.
- **Vagrant, with either provider.** The current version is not open source, the open-source line in the distributions is from 2023, Ubuntu 24.04 does not package it, and the official Debian 13 box covers one provider on one architecture. A new open-source project should not build on it.
- **libvirt with the cloud image.** Everything is installed on the owner's machine already and it would work. But creating, addressing, waiting for and resetting a node would be our own scripts, which is what Incus provides in four commands. No macOS path.
- **Docker or Podman running systemd.** The only option preinstalled on the runner. systemd in an application container needs special flags, there is no Debian image with systemd, and quotas need a privileged container, which stops being a boundary for the isolation tests. It gains nothing over an Incus container, which is built for this.
- **systemd-nspawn.** No ready image, needs root, Linux only, and the same shared-kernel limits as any container.

## Measurements

Two nodes, 2 GiB and 2 vCPUs each, Debian 13 image build 20261007. Seconds, from `infrastructure/testing/poc/nodes.sh up`. "Here" is the owner's machine: Fedora 44, 16 threads, 14 GB of RAM, Incus 6.23, storage driver btrfs. "Runner" is `ubuntu-24.04` in this repository while it is private: 2 vCPUs, 8 GB of RAM, Incus 6.0.0, storage driver dir.

| | Incus container, here | Incus VM, here | Lima, here | Incus container, runner | Incus VM, runner |
|---|---|---|---|---|---|
| Image download (cold) | 5 | 12 | included below | 5 | 11 |
| Create two nodes | 7 | 29 | 36 cold, 25 warm | 25 | 99 |
| Reset two nodes | 4 (median of 3) | 18 (median of 5) | 25 (delete and start) | 7 (one run) | 48 (one run) |
| Verify (14 checks) | 1 | 1 | not run as a script | 1 | 2 |
| Whole CI job | | | | 1 min 33 s | 4 min 10 s |

"Create" is launch, boot, stop, snapshot, start and boot again. "Reset" is stop, restore the snapshot, start and boot. The public-repository runner, with twice the vCPUs and RAM, was not measured.

The runner columns are run 37943954220 of the committed workflow (commit ea7be30e), which installs `incus` for containers and `incus qemu-system` for virtual machines. They are one run each. Three earlier runs used other install lines: two failed in the install step, and in the third the container job, which then installed `qemu-system` too, spent ten minutes downloading it from a slow mirror and was cancelled. The earlier runs that did finish measured 27 and 90 seconds to create and 4 and 44 seconds to reset.

`verify.sh` passed 14 of 14 checks for both Incus node types, here and on the runner: each node is clean, PID 1 is systemd, the system state is `running` (not `degraded`), it is Debian 13 on cgroups v2, and a transient unit with `Slice=hostmesh-poc.slice` and `PrivateTmp=yes` runs in `/hostmesh.slice/hostmesh-poc.slice/` and cannot see a file in the node's `/tmp`. The same scripts ran unchanged on Incus 6.0.0 and 6.23.

`probe.sh`, run inside one node:

| Probe | Incus container | Incus VM | Lima |
|---|---|---|---|
| Slice limits: `MemoryMax=64M` and `TasksMax=16` set on the slice and read back from its cgroup | PASS | PASS | PASS, in an earlier form: set on a unit |
| The task limit holds: a unit in the slice that starts 40 processes is refused some | PASS | PASS | not run |
| The memory limit holds: a unit in the slice that holds 200 MB is killed | PASS | PASS | not run |
| Delegation: a unit with `Delegate=yes` sees the memory and pids controllers and creates a cgroup of its own | PASS | PASS | not run |
| nftables: reject TCP 25 for one UID, and the rule's counter moves | PASS | PASS | PASS |
| Nginx active | PASS | PASS | PASS |
| PHP-FPM 8.4 active | PASS | PASS | PASS |
| MariaDB active | PASS | PASS | PASS |
| PowerDNS with a SQLite backend answers a zone's SOA | PASS | PASS | PASS |
| User quota: a 1 MiB hard limit holds on ext4 | **FAIL** | PASS | PASS |

The container fails the quota probe with "failed to setup loop device": it has no `/dev/loop*`. The Incus documentation lists no option for loop devices or user quotas in a container. Passing a block device in from the host with mount interception was not tried. The Incus results were the same here and on the runner: 9 of 10 in a container, 10 of 10 in a virtual machine. Lima was run before the three probes marked "not run" existed.

A container uses the kernel of its host: 7.2 on the owner's machine and Ubuntu's on the runner, neither of them Debian 13's. What passes in the container tier is therefore true of those kernels. Only the virtual machine tier runs Debian's own kernel.

## Decision

- **Incus runs the test nodes**, as two instances named `hostmesh-node-1` and `hostmesh-node-2` from the image `images:debian/13`, each with 2 GiB of RAM and 2 vCPUs.
- **System containers are the default.** They run everything except filesystem user quotas, and two of them are created in under half a minute and reset in 4 to 7 seconds even on the small runner.
- **Virtual machines run the isolation suite** (spec section 37) and any test that needs a quota. They are the same command with another instance type. In a virtual machine the quota filesystem is an ext4 filesystem created with the quota feature, as in the probe. How a production node provides that filesystem is not decided here.
- **Reset is a snapshot restore.** Each node is snapshotted once, stopped, as `clean`, after its first boot. A reset stops the node, restores `clean` and starts it.
- **Every pull request** that touches the agent or the harness runs the integration tests on two containers. Budget: nodes ready within 60 seconds, a reset within 10 seconds.
- **Nightly and before a release**, the full suite runs on two virtual machines, including the isolation suite. Budget: nodes ready within 150 seconds, a reset within 60 seconds.
- **Virtual machines on pull requests are possible, not decided.** The measured job takes about four minutes on the smallest runner. Whether the isolation suite moves to pull requests is decided when its length is known and the repository is public.
- **Tested with Incus 6.0.0 and 6.23.** The 7.x line was not run. The workflow names `ubuntu-24.04`, not `ubuntu-latest`.
- **macOS is not supported yet.** Nothing in this decision depends on a Linux-only feature of the host other than Incus itself.

## Consequences

- A contributor who wants to run integration tests locally installs Incus and, where firewalld is active, puts `incusbr0` into the `trusted` zone; without that a node gets no address. Docker on the same machine needed no change. Membership of `incus-admin` is equivalent to root on that machine, and the documentation of the harness must say so. Virtual machines need `/dev/kvm`.
- Unit and contract tests stay as they are and need none of this.
- The image is a daily build. On a fresh runner, two runs a day apart may use different package versions; a local copy keeps the build it was downloaded with until it is deleted. Issue #17 decides whether to pin an image fingerprint.
- Found while building the proof of concept, for issue #17:
  - `incus launch` and `incus snapshot create` read configuration from standard input when it is not a terminal and wait for it. A script must give them an empty one.
  - On the runner, `apt-get install --no-install-recommends incus` leaves out `dnsmasq`, and `incus admin init` fails. With `dnsmasq-base` and `qemu-system` added but still without recommended packages, virtual machines fail with "QEMU failed to run feature checks"; which recommended package is missing was not found out. `apt install incus qemu-system` with recommended packages works, as the Incus documentation says.
  - `qemu-system` installs the emulators of every architecture: in one run that download took ten minutes on a slow mirror. The workflow no longer installs it in the container job. Whether a job with virtual machines can install less of it is open.
  - The Debian image runs `systemd-resolved` on 127.0.0.53 and 127.0.0.54, port 53. PowerDNS must listen on the node's own address. Its package starts without a backend, and a zone created while it runs is refused until its zone cache refreshes.
  - Whether the runner needs the `DOCKER-USER` firewall rules for the Incus bridge is not known: they were applied before the first launch.
- Issue #17 takes `nodes.sh` and `verify.sh` as a starting point and adds what they do not have: pytest fixtures that create, reset and address the nodes, the agent installed on them, the `dns` capability on both, the quota filesystem in virtual machines, and the lasting workflows. The proof of concept and its manual workflow `test-nodes-poc.yml` are removed or replaced there.
- The macOS path is open. Two candidates exist and neither is proven: the Incus server inside a Linux VM (Colima documents an Incus runtime), or Lima's native virtual machines, whose reset would be delete and start. A contributor on macOS is the trigger to test them.
- Revisit this decision if hosted runners lose `/dev/kvm`, if a supported way to enforce user quotas inside an Incus container appears (the nightly tier could then shrink), or if the virtual machine tier turns out to be fast enough on public runners to drop the container tier.
