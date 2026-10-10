# SPDX-License-Identifier: Apache-2.0
"""An in-memory Incus: instances, snapshots and images, driven by the same arguments."""

from collections.abc import Sequence
from dataclasses import dataclass, field

from hostmesh_testing.incus import Result


def _ok(stdout: str = "") -> Result:
    return Result(0, stdout, "")


def _error(message: str) -> Result:
    return Result(1, "", f"Error: {message}\n")


@dataclass
class Instance:
    incus_type: str  # CONTAINER or VIRTUAL-MACHINE, as `incus list -c t` prints it
    state: str  # RUNNING or STOPPED
    config: dict[str, str]
    snapshots: set[str] = field(default_factory=set)


class FakeClock:
    """Time that moves only when something sleeps."""

    def __init__(self) -> None:
        self.now = 0.0

    def __call__(self) -> float:
        return self.now

    def sleep(self, seconds: float) -> None:
        self.now += seconds


class FakeIncus:
    """Answers the commands the harness sends, and fails loudly on any other."""

    def __init__(self) -> None:
        self.instances: dict[str, Instance] = {}
        self.images: set[str] = set()
        self.calls: list[tuple[str, ...]] = []
        self.system_state = "running"
        self.address = "10.99.0.2 (eth0)"
        self.exec_result = _ok()

    def verbs(self) -> list[str]:
        """The first two words of every call that changed something."""
        changing = {"launch", "stop", "start", "delete", "snapshot", "image"}
        return [
            " ".join(call[:2])
            for call in self.calls
            if call[0] in changing and call[:2] not in {("snapshot", "show"), ("image", "info")}
        ]

    def run(
        self, args: Sequence[str], *, stdin: str | None = None, timeout: float = 120.0
    ) -> Result:
        call = tuple(args)
        self.calls.append(call)
        match call:
            case ("list", pattern, "-f", "csv", "-c", columns):
                instance = self.instances.get(pattern.strip("^$"))
                if instance is None:
                    return _ok()
                running = instance.state == "RUNNING"
                values = {
                    "t": instance.incus_type,
                    "s": instance.state,
                    "4": self.address if running else "",
                }
                return _ok(",".join(values[column] for column in columns) + "\n")
            case ("list", _, "-c", _):
                return _ok("NAME  STATE\n")
            case ("config", "get", name, key):
                return _ok(self.instances[name].config.get(key, "") + "\n")
            case ("snapshot", "show", name, snapshot):
                return _ok() if snapshot in self.instances[name].snapshots else _error("not found")
            case ("snapshot", "create", name, snapshot):
                if self.instances[name].state != "STOPPED":
                    return _error("the fake takes snapshots of stopped instances only")
                self.instances[name].snapshots.add(snapshot)
                return _ok()
            case ("snapshot", "restore", name, snapshot):
                instance = self.instances[name]
                if instance.state != "STOPPED" or snapshot not in instance.snapshots:
                    return _error("cannot restore")
                return _ok()
            case ("image", "info", alias):
                return _ok() if alias in self.images else _error("image not found")
            case ("image", "copy", "--quiet", _, "local:", "--alias", alias, *_):
                self.images.add(alias)
                return _ok()
            case ("launch", "--quiet", alias, name, *rest):
                if alias not in self.images:
                    return _error("image not found")
                pairs = (pair.partition("=") for pair in rest if "=" in pair)
                config = {key: value for key, _, value in pairs}
                incus_type = "VIRTUAL-MACHINE" if "--vm" in rest else "CONTAINER"
                self.instances[name] = Instance(incus_type, "RUNNING", config)
                return _ok()
            case ("exec", name, "--", "systemctl", "is-system-running"):
                if self.instances[name].state != "RUNNING":
                    return _error("Instance is not running")
                return _ok(self.system_state + "\n")
            case ("exec", _, "--", *_):
                return self.exec_result
            case ("stop", name) | ("stop", "--force", name):
                if self.instances[name].state != "RUNNING":
                    return _error("The instance is already stopped")
                self.instances[name].state = "STOPPED"
                return _ok()
            case ("start", name):
                self.instances[name].state = "RUNNING"
                return _ok()
            case ("delete", "--force", name):
                del self.instances[name]
                return _ok()
            case _:
                raise AssertionError(f"the fake does not know this incus call: {call}")
