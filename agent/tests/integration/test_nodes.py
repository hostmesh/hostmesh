# SPDX-License-Identifier: Apache-2.0
"""The test nodes themselves: Debian 13 under systemd, on cgroups v2, with `dns`."""

import ipaddress

import pytest
from hostmesh_testing import QUOTA_MOUNT, Node, NodeHarness

pytestmark = pytest.mark.integration

_MARKER = "/var/lib/hostmesh-test-left-behind"


def test_pid_1_is_systemd(node: Node) -> None:
    assert node.run(["cat", "/proc/1/comm"]).stdout.strip() == "systemd"


def test_systemd_finished_booting_without_a_failed_unit(node: Node) -> None:
    # `degraded` is a failure: it means a unit failed on a fresh node.
    state = node.run(["systemctl", "is-system-running", "--wait"], check=False).stdout.strip()
    failed = node.run(["systemctl", "list-units", "--failed", "--plain", "--no-legend"]).stdout

    assert (state, failed.strip()) == ("running", "")


def test_the_node_reports_cgroups_v2(node: Node) -> None:
    assert node.run(["stat", "-fc", "%T", "/sys/fs/cgroup"]).stdout.strip() == "cgroup2fs"


def test_the_node_runs_debian_13(node: Node) -> None:
    release = node.run(["cat", "/etc/os-release"]).stdout.splitlines()

    assert "ID=debian" in release
    assert 'VERSION_ID="13"' in release


def test_the_node_carries_the_dns_capability(node: Node) -> None:
    assert "dns" in node.capabilities


def test_the_node_has_an_ipv4_address(node: Node) -> None:
    assert ipaddress.IPv4Address(node.address()).is_private


def test_reset_removes_what_a_test_left_behind(harness: NodeHarness) -> None:
    for node in harness.nodes:
        node.run(["touch", _MARKER])

    harness.reset()

    # `test -e` exits 1 when the file is missing. Any other code is a broken node.
    codes = [node.run(["test", "-e", _MARKER], check=False).returncode for node in harness.nodes]
    assert codes == [1, 1]


def _needs_a_virtual_machine(node: Node) -> None:
    if node.node_type != "vm":
        pytest.skip("user quotas need a virtual machine: a container has no loop device (0015)")


def test_a_virtual_machine_mounts_an_ext4_filesystem_with_user_quotas(node: Node) -> None:
    _needs_a_virtual_machine(node)

    fstype, options = node.run(["findmnt", "-no", "FSTYPE,OPTIONS", QUOTA_MOUNT]).stdout.split()
    source = node.run(["findmnt", "-no", "SOURCE", QUOTA_MOUNT]).stdout.strip()
    features = node.run(["dumpe2fs", "-h", source]).stdout

    assert fstype == "ext4"
    assert "usrquota" in options.split(",")
    assert (
        "quota"
        in next(
            line for line in features.splitlines() if line.startswith("Filesystem features")
        ).split()
    )


def test_a_hard_user_quota_holds(node: Node) -> None:
    _needs_a_virtual_machine(node)
    target = f"{QUOTA_MOUNT}/quota-test"
    # 1 MiB, soft and hard, for the user nobody (spec section 13: enforced hard).
    node.run(["setquota", "-u", "nobody", "1024", "1024", "0", "0", QUOTA_MOUNT])
    try:
        written = node.run(
            [
                "setpriv",
                "--reuid=65534",
                "--regid=65534",
                "--clear-groups",
                "dd",
                "if=/dev/zero",
                f"of={target}",
                "bs=1M",
                "count=4",
                "status=none",
            ],
            check=False,
        )
        size = int(node.run(["stat", "-c", "%s", target]).stdout)
    finally:
        node.run(["rm", "-f", target])
        node.run(["setquota", "-u", "nobody", "0", "0", "0", "0", QUOTA_MOUNT])

    assert written.returncode != 0
    assert "Disk quota exceeded" in written.stderr
    assert size <= 1024 * 1024
