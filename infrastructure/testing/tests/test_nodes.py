# SPDX-License-Identifier: Apache-2.0
"""The harness's decisions, against the in-memory Incus."""

import pytest

from hostmesh_testing.incus import HarnessError, Result
from hostmesh_testing.nodes import (
    CAPABILITIES_KEY,
    IMAGE_KEY,
    NODE_NAMES,
    CommandFailed,
    Config,
    Node,
    NodeHarness,
)
from tests.fake_incus import FakeClock, FakeIncus

NODE_1, NODE_2 = NODE_NAMES


def _harness(
    fake: FakeIncus,
    config: Config | None = None,
    *,
    kvm: bool = True,
    lines: list[str] | None = None,
) -> NodeHarness:
    clock = FakeClock()
    fake.clock = clock
    return NodeHarness(
        fake,
        config,
        clock=clock,
        sleep=clock.sleep,
        kvm_writable=lambda: kvm,
        report=(lines if lines is not None else []).append,
    )


def test_up_on_an_empty_host_creates_two_running_nodes_with_a_clean_snapshot() -> None:
    fake = FakeIncus()

    _harness(fake).up()

    assert sorted(fake.instances) == [NODE_1, NODE_2]
    for instance in fake.instances.values():
        assert (instance.incus_type, instance.state, instance.snapshots) == (
            "CONTAINER",
            "RUNNING",
            {"clean"},
        )


def test_a_new_node_has_the_size_the_image_and_the_dns_capability_of_decision_0015() -> None:
    fake = FakeIncus()

    _harness(fake).up()

    # Without the keys Incus records by itself.
    recorded = {"image.serial", "volatile.base_image"}
    config = fake.instances[NODE_1].config
    assert {key: value for key, value in config.items() if key not in recorded} == {
        "limits.memory": "2GiB",
        "limits.cpu": "2",
        IMAGE_KEY: "images:debian/13",
        CAPABILITIES_KEY: "dns",
    }


def test_the_snapshot_is_taken_between_a_stop_and_a_start() -> None:
    fake = FakeIncus()

    _harness(fake).create()

    # The fake refuses a snapshot of a running instance, so reaching here
    # already proves the order. The list makes it readable.
    assert fake.verbs() == [
        "image copy",
        "launch --quiet",
        "launch --quiet",
        f"stop {NODE_1}",
        "snapshot create",
        f"start {NODE_1}",
        f"stop {NODE_2}",
        "snapshot create",
        f"start {NODE_2}",
    ]


def _commands_on(fake: FakeIncus, name: str) -> list[tuple[str, ...]]:
    """The commands run on a node, without the harness's boot checks."""
    return [
        call[3:]
        for call in fake.calls
        if call[:3] == ("exec", name, "--") and call[3:5] != ("systemctl", "is-system-running")
    ]


# Issue #169, decision 0015: a virtual machine carries an ext4 filesystem
# with user quotas, made as the quota probe made it, inside its snapshot.
_QUOTA_COMMANDS = [
    ("env", "DEBIAN_FRONTEND=noninteractive", "apt-get", "update", "-qq"),
    (
        "env",
        "DEBIAN_FRONTEND=noninteractive",
        "apt-get",
        "install",
        "-y",
        "-qq",
        "--no-install-recommends",
        "quota",
    ),
    ("truncate", "-s", "256M", "/var/lib/hostmesh-quota.img"),
    ("mkfs.ext4", "-q", "-O", "quota", "-E", "quotatype=usrquota", "/var/lib/hostmesh-quota.img"),
    ("mkdir", "-p", "/srv/hostmesh-quota"),
    ("tee", "-a", "/etc/fstab"),
    ("systemctl", "daemon-reload"),
    ("mount", "/srv/hostmesh-quota"),
    ("chmod", "1777", "/srv/hostmesh-quota"),
    # The mount enforces the quotas already; quotaon would fail at every boot.
    ("systemctl", "mask", "quotaon@srv-hostmesh\\x2dquota.service"),
]


def test_a_virtual_machine_gets_the_quota_filesystem_before_its_snapshot() -> None:
    fake = FakeIncus()

    _harness(fake, Config(node_type="vm")).create()

    for name in NODE_NAMES:
        assert _commands_on(fake, name) == _QUOTA_COMMANDS
    # Mounted at every boot, so a restored snapshot has it too.
    assert {stdin for _, stdin in fake.stdins} == {
        "/var/lib/hostmesh-quota.img /srv/hostmesh-quota ext4 loop,usrquota 0 0\n"
    }
    # Made while each node runs, before it is stopped for its snapshot.
    for name in NODE_NAMES:
        last_step = max(
            index
            for index, call in enumerate(fake.calls)
            if call[:3] == ("exec", name, "--") and call[3:] == _QUOTA_COMMANDS[-1]
        )
        assert last_step < fake.calls.index(("stop", name))


def test_a_container_gets_no_quota_filesystem() -> None:
    fake = FakeIncus()

    _harness(fake).create()

    assert all(_commands_on(fake, name) == [] for name in NODE_NAMES)


def test_a_reset_does_not_make_the_quota_filesystem_again() -> None:
    fake = FakeIncus()
    config = Config(node_type="vm")
    _harness(fake, config).up()
    fake.calls.clear()

    _harness(fake, config).up()

    assert all(_commands_on(fake, name) == [] for name in NODE_NAMES)


def test_a_failed_step_stops_the_creation_before_the_snapshot() -> None:
    fake = FakeIncus()
    fake.exec_failures = {"mkfs.ext4": Result(1, "", "mkfs.ext4: no space left\n")}

    with pytest.raises(CommandFailed, match=r"mkfs\.ext4.*no space left"):
        _harness(fake, Config(node_type="vm")).create()

    assert "snapshot create" not in fake.verbs()


def test_up_a_second_time_resets_and_creates_nothing() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.calls.clear()

    _harness(fake).up()

    assert fake.verbs() == [
        "stop --force",
        "snapshot restore",
        f"start {NODE_1}",
        "stop --force",
        "snapshot restore",
        f"start {NODE_2}",
    ]


def test_up_creates_the_missing_node_and_resets_the_other() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    del fake.instances[NODE_2]
    fake.calls.clear()

    _harness(fake).up()

    assert fake.verbs() == [
        "launch --quiet",
        f"stop {NODE_2}",
        "snapshot create",
        f"start {NODE_2}",
        "stop --force",
        "snapshot restore",
        f"start {NODE_1}",
    ]
    assert fake.instances[NODE_2].snapshots == {"clean"}


def test_a_node_without_a_snapshot_is_made_again() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.instances[NODE_1].snapshots.clear()
    fake.calls.clear()

    _harness(fake).up()

    assert fake.verbs()[:2] == ["delete --force", "launch --quiet"]
    assert fake.instances[NODE_1].snapshots == {"clean"}


def test_a_stopped_node_is_reset_without_stopping_it_first() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.instances[NODE_1].state = "STOPPED"
    fake.calls.clear()

    _harness(fake).reset()

    assert fake.verbs()[:2] == ["snapshot restore", f"start {NODE_1}"]
    assert fake.instances[NODE_1].state == "RUNNING"


# Issue #97. After a boot the first incus command starts the daemon, and the
# daemon then starts by itself every node that was running when it went down.
# A reset in those seconds reads STOPPED and finds the node running later.
@pytest.mark.parametrize("moment", ["before restore", "after restore"])
def test_a_node_the_daemon_starts_by_itself_during_a_reset_is_not_an_error(moment: str) -> None:
    fake = FakeIncus()
    _harness(fake).up()
    for name in NODE_NAMES:
        fake.instances[name].state = "STOPPED"
        fake.instances[name].dirty = True
    fake.daemon_starts = {NODE_1: moment}

    _harness(fake).up()

    for name in NODE_NAMES:
        assert fake.instances[name].state == "RUNNING"
        assert fake.instances[name].dirty is False


def test_a_node_that_does_not_start_after_a_reset_is_reported() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.start_error = "Failed to run: forkstart: exit status 1"

    with pytest.raises(HarnessError, match=f"incus start {NODE_1} failed: .*forkstart"):
        _harness(fake).reset()

    assert fake.instances[NODE_1].state == "STOPPED"


def test_a_reset_restores_what_a_test_left_behind() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.instances[NODE_2].dirty = True

    _harness(fake).reset()

    assert fake.instances[NODE_2].dirty is False


def test_up_refuses_a_node_of_the_other_type() -> None:
    fake = FakeIncus()
    _harness(fake).up()

    with pytest.raises(HarnessError, match=f"{NODE_1} exists as CONTAINER.*Run 'destroy' first"):
        _harness(fake, Config(node_type="vm")).up()


def test_up_refuses_a_node_made_from_another_image() -> None:
    fake = FakeIncus()
    _harness(fake).up()

    with pytest.raises(HarnessError, match=r"created from 'images:debian/13'.*Run 'destroy' first"):
        _harness(fake, Config(image="images:debian/14")).up()


def test_up_deletes_nothing_when_it_refuses_another_node() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.instances[NODE_1].snapshots.clear()
    fake.instances[NODE_2].config[IMAGE_KEY] = "images:debian/12"
    fake.calls.clear()

    with pytest.raises(HarnessError, match=f"{NODE_2} was created from.*Run 'destroy' first"):
        _harness(fake).up()

    assert fake.verbs() == []


def test_virtual_machines_need_a_writable_kvm_device() -> None:
    fake = FakeIncus()

    with pytest.raises(HarnessError, match=r"/dev/kvm"):
        _harness(fake, Config(node_type="vm"), kvm=False).up()

    assert fake.calls == []


def test_virtual_machines_are_copied_and_launched_as_such() -> None:
    fake = FakeIncus()

    _harness(fake, Config(node_type="vm")).up()

    copy = next(call for call in fake.calls if call[:2] == ("image", "copy"))
    assert "--vm" in copy
    assert {instance.incus_type for instance in fake.instances.values()} == {"VIRTUAL-MACHINE"}
    assert fake.images == {"hostmesh-vm-images-debian-13"}


def test_the_image_is_downloaded_once() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    _harness(fake).destroy()
    fake.calls.clear()

    _harness(fake).up()

    assert "image copy" not in fake.verbs()


def test_a_node_that_never_finishes_booting_is_reported_with_its_last_state() -> None:
    fake = FakeIncus()
    fake.system_state = "starting"

    with pytest.raises(
        HarnessError, match=f"{NODE_1} did not finish booting within 180s.*'starting'"
    ):
        _harness(fake).up()


def test_reset_refuses_when_a_node_is_missing_and_deletes_nothing() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.instances[NODE_1].snapshots.clear()
    fake.calls.clear()

    with pytest.raises(HarnessError, match=f"{NODE_1} cannot be reset.*Run 'up' first"):
        _harness(fake).reset()

    assert fake.verbs() == []


def test_destroy_deletes_what_exists_and_accepts_an_empty_host() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    del fake.instances[NODE_1]

    _harness(fake).destroy()
    _harness(fake).destroy()

    assert fake.instances == {}


def test_up_reports_how_long_each_phase_took() -> None:
    fake = FakeIncus()
    first: list[str] = []
    second: list[str] = []

    _harness(fake, lines=first).up()
    _harness(fake, lines=second).up()

    assert [line.split()[:2] for line in _timings(first)] == [["TIME", "image"], ["TIME", "create"]]
    assert [line.split()[:2] for line in _timings(second)] == [["TIME", "reset"]]


def _timings(lines: list[str]) -> list[str]:
    """The lines about time: the TIME lines and the budget warnings."""
    return [line for line in lines if not line.startswith("IMAGE ")]


# Issue #170. The image is a daily build and is not pinned: each run says
# which build its nodes were made from, so a failure can be tied to a new one.
def test_up_reports_the_build_each_new_node_was_made_from() -> None:
    fake = FakeIncus()
    lines: list[str] = []

    _harness(fake, lines=lines).up()

    assert [line for line in lines if line.startswith("IMAGE ")] == [
        f"IMAGE {NODE_1} images:debian/13 20261014_05:24 7439b6b23e1a",
        f"IMAGE {NODE_2} images:debian/13 20261014_05:24 7439b6b23e1a",
    ]


def test_a_reset_reports_the_build_the_nodes_were_made_from_not_a_newer_one() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.build = ("20261015_05:24", "b93220c609a3" + "0" * 52)
    lines: list[str] = []

    _harness(fake, lines=lines).up()

    assert [line for line in lines if line.startswith("IMAGE ")] == [
        f"IMAGE {NODE_1} images:debian/13 20261014_05:24 7439b6b23e1a",
        f"IMAGE {NODE_2} images:debian/13 20261014_05:24 7439b6b23e1a",
    ]


def test_nodes_made_from_two_builds_say_so() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    # A newer build, fetched after the local copy was deleted, for one new node.
    fake.build = ("20261015_05:24", "b93220c609a3" + "0" * 52)
    fake.images.clear()
    del fake.instances[NODE_2]
    lines: list[str] = []

    _harness(fake, lines=lines).up()

    assert [line for line in lines if line.startswith("IMAGE ")] == [
        f"IMAGE {NODE_1} images:debian/13 20261014_05:24 7439b6b23e1a",
        f"IMAGE {NODE_2} images:debian/13 20261015_05:24 b93220c609a3",
    ]


def test_a_node_that_does_not_record_its_build_is_reported_as_unknown() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    for instance in fake.instances.values():
        del instance.config["image.serial"]
        del instance.config["volatile.base_image"]
    lines: list[str] = []

    _harness(fake, lines=lines).up()

    assert [line for line in lines if line.startswith("IMAGE ")] == [
        f"IMAGE {NODE_1} images:debian/13 unknown unknown",
        f"IMAGE {NODE_2} images:debian/13 unknown unknown",
    ]


# Issue #123. The budgets of decision 0015 are advisory: an overrun is said,
# and fails nothing. Containers: ready in 60 seconds, reset in 10.
def test_a_reset_over_its_budget_is_warned_about_and_fails_nothing() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.durations = {"snapshot restore": 6.0}
    lines: list[str] = []

    _harness(fake, lines=lines).up()

    assert _timings(lines) == [
        "TIME reset 12",
        "WARNING reset took 12 s, budget 10 s (decision 0015)",
    ]
    assert all(fake.instances[name].state == "RUNNING" for name in NODE_NAMES)


def test_a_phase_within_its_budget_is_not_warned_about() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.durations = {"snapshot restore": 5.0}
    lines: list[str] = []

    _harness(fake, lines=lines).up()

    assert _timings(lines) == ["TIME reset 10"]


def test_ready_is_the_image_and_the_creation_together() -> None:
    fake = FakeIncus()
    fake.durations = {"image copy": 30.0, "launch": 20.0}
    lines: list[str] = []

    _harness(fake, lines=lines).up()

    assert _timings(lines) == [
        "TIME image 30",
        "TIME create 40",
        "WARNING ready took 70 s, budget 60 s (decision 0015)",
    ]


def test_virtual_machines_have_budgets_of_their_own() -> None:
    config = Config(node_type="vm")
    fake = FakeIncus()
    _harness(fake, config).up()
    lines: list[str] = []

    # 50 seconds: over a container's 10, within a virtual machine's 60.
    fake.durations = {"snapshot restore": 25.0}
    _harness(fake, config, lines=lines).up()
    # 70 seconds.
    fake.durations = {"snapshot restore": 35.0}
    _harness(fake, config, lines=lines).up()

    assert _timings(lines) == [
        "TIME reset 50",
        "TIME reset 70",
        "WARNING reset took 70 s, budget 60 s (decision 0015)",
    ]


def test_under_github_actions_the_warning_is_an_annotation() -> None:
    config = Config.from_environment({"GITHUB_ACTIONS": "true"})
    fake = FakeIncus()
    _harness(fake, config).up()
    fake.durations = {"snapshot restore": 6.0}
    lines: list[str] = []

    _harness(fake, config, lines=lines).up()

    assert _timings(lines)[1] == (
        "::warning title=Test node budget::reset took 12 s, budget 10 s (decision 0015)"
    )


def test_the_warning_is_plain_text_outside_github_actions() -> None:
    assert Config.from_environment({}).annotate is False
    assert Config.from_environment({"GITHUB_ACTIONS": "false"}).annotate is False
    assert Config.from_environment({"GITHUB_ACTIONS": "true"}).annotate is True


def test_the_harness_hands_out_both_nodes_and_refuses_an_unknown_name() -> None:
    harness = _harness(FakeIncus())

    assert [node.name for node in harness.nodes] == [NODE_1, NODE_2]
    assert harness.node(NODE_2).name == NODE_2
    with pytest.raises(HarnessError, match="unknown node 'web-1'"):
        harness.node("web-1")


def test_a_command_on_a_node_goes_through_incus_exec() -> None:
    fake = FakeIncus()
    fake.exec_result = Result(0, "systemd\n", "")

    result = Node(NODE_1, "container", fake).run(["cat", "/proc/1/comm"])

    assert result.stdout == "systemd\n"
    assert fake.calls == [("exec", NODE_1, "--", "cat", "/proc/1/comm")]


def test_a_failing_command_raises_unless_the_caller_wants_the_exit_code() -> None:
    fake = FakeIncus()
    fake.exec_result = Result(2, "", "no such file\n")
    node = Node(NODE_1, "container", fake)

    assert node.run(["test", "-e", "/x"], check=False).returncode == 2
    with pytest.raises(
        CommandFailed, match=f"{NODE_1}: test -e /x exited 2: no such file"
    ) as raised:
        node.run(["test", "-e", "/x"])
    assert raised.value.result.returncode == 2


def test_capabilities_are_read_from_incus_each_time() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    node = Node(NODE_1, "container", fake)

    assert node.capabilities == frozenset({"dns"})
    fake.instances[NODE_1].config[CAPABILITIES_KEY] = "dns,web"
    assert node.capabilities == frozenset({"dns", "web"})
    fake.instances[NODE_1].config[CAPABILITIES_KEY] = ""
    assert node.capabilities == frozenset()


def test_the_address_is_the_first_ipv4_address_incus_lists() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.address = '"10.99.0.7 (eth0)\n172.17.0.1 (docker0)"'

    assert Node(NODE_1, "container", fake).address() == "10.99.0.7"


def test_a_node_without_an_address_names_the_usual_cause() -> None:
    fake = FakeIncus()
    _harness(fake).up()
    fake.address = ""

    with pytest.raises(HarnessError, match=f"{NODE_1} has no IPv4 address.*incusbr0"):
        Node(NODE_1, "container", fake).address(timeout=0.0)


def test_waiting_for_an_address_runs_on_the_harness_clock() -> None:
    fake = FakeIncus()
    clock = FakeClock()
    harness = NodeHarness(fake, clock=clock, sleep=clock.sleep)
    harness.up()
    fake.address = ""
    before = clock.now

    with pytest.raises(HarnessError, match=f"{NODE_1} has no IPv4 address after 2s"):
        harness.node(NODE_1).address(timeout=2.0)

    assert clock.now - before == 2.0


def test_the_environment_chooses_type_and_image() -> None:
    assert Config.from_environment({}) == Config()
    assert Config.from_environment(
        {"HOSTMESH_NODE_TYPE": "vm", "HOSTMESH_NODE_IMAGE": "images:debian/13/cloud"}
    ) == Config(node_type="vm", image="images:debian/13/cloud")


def test_an_unknown_node_type_is_refused() -> None:
    with pytest.raises(
        HarnessError, match="HOSTMESH_NODE_TYPE must be 'container' or 'vm', got 'lxc'"
    ):
        Config.from_environment({"HOSTMESH_NODE_TYPE": "lxc"})
