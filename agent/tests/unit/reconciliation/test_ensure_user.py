# SPDX-License-Identifier: Apache-2.0
"""ensure_user against the fakes: the tests every ensure_* needs (spec section 37).

Create, no-op when converged, drift repair, apply twice, and resume after an
interruption.
"""

import contextlib
from dataclasses import dataclass, replace
from pathlib import PurePosixPath

import pytest
from hypothesis import given
from hypothesis import strategies as st

from agent.reconciliation.user import Change, PathConflictError, UserSpec, ensure_user
from agent.services.filesystem.interface import Entry, EntryKind
from agent.services.users.interface import User
from tests.fakes.filesystem import FakeFilesystem
from tests.fakes.journal import InjectedFaultError, Journal
from tests.fakes.users import FakeUsers

HOME = PurePosixPath("/home/acct1")
OLD_HOME = PurePosixPath("/home/old")
NOLOGIN = PurePosixPath("/usr/sbin/nologin")
BASH = PurePosixPath("/bin/bash")
SPEC = UserSpec(name="acct1", home=HOME, shell=NOLOGIN, home_mode=0o750)

CONVERGED_USER = User("acct1", uid=1000, gid=1000, home=HOME, shell=NOLOGIN)
CONVERGED_HOME = Entry(EntryKind.DIRECTORY, uid=1000, gid=1000, mode=0o750)


@dataclass
class World:
    """Both fakes on one journal: the whole system ensure_user can see."""

    journal: Journal
    users: FakeUsers
    filesystem: FakeFilesystem

    def ensure(self) -> tuple[Change, ...]:
        return ensure_user(SPEC, users=self.users, filesystem=self.filesystem)

    def state(self) -> tuple[dict[str, User], dict[PurePosixPath, Entry]]:
        return self.users.snapshot(), self.filesystem.snapshot()


def make_world(*, user: User | None = None, home: Entry | None = None) -> World:
    journal = Journal()
    world = World(journal, FakeUsers(journal), FakeFilesystem(journal))
    world.filesystem.put(HOME.parent, Entry(EntryKind.DIRECTORY, uid=0, gid=0, mode=0o755))
    if user is not None:
        world.users.put(user)
    if home is not None:
        world.filesystem.put(HOME, home)
    return world


def assert_converged(world: World) -> None:
    user = world.users.get(SPEC.name)
    assert user is not None
    assert (user.home, user.shell) == (SPEC.home, SPEC.shell)
    assert world.filesystem.stat(SPEC.home) == Entry(
        EntryKind.DIRECTORY, uid=user.uid, gid=user.gid, mode=SPEC.home_mode
    )


def test_creates_the_user_and_its_home() -> None:
    world = make_world()

    changes = world.ensure()

    assert changes == (Change.USER_CREATED, Change.HOME_CREATED, Change.HOME_OWNER_SET)
    assert_converged(world)


def test_is_a_no_op_when_converged() -> None:
    world = make_world(user=CONVERGED_USER, home=CONVERGED_HOME)
    before = world.state()

    changes = world.ensure()

    assert changes == ()
    assert world.journal.mutations == []
    assert world.state() == before


@pytest.mark.parametrize(
    ("user", "home", "expected"),
    [
        pytest.param(
            replace(CONVERGED_USER, shell=BASH),
            CONVERGED_HOME,
            (Change.USER_MODIFIED,),
            id="wrong-shell",
        ),
        pytest.param(
            CONVERGED_USER,
            replace(CONVERGED_HOME, uid=0, gid=0),
            (Change.HOME_OWNER_SET,),
            id="wrong-owner",
        ),
        pytest.param(
            CONVERGED_USER,
            replace(CONVERGED_HOME, mode=0o777),
            (Change.HOME_MODE_SET,),
            id="wrong-mode",
        ),
        pytest.param(
            CONVERGED_USER,
            None,
            (Change.HOME_CREATED, Change.HOME_OWNER_SET),
            id="missing-home",
        ),
        pytest.param(
            replace(CONVERGED_USER, home=OLD_HOME, shell=BASH),
            Entry(EntryKind.DIRECTORY, uid=0, gid=0, mode=0o700),
            (Change.USER_MODIFIED, Change.HOME_OWNER_SET, Change.HOME_MODE_SET),
            id="everything-wrong",
        ),
    ],
)
def test_repairs_drift(user: User, home: Entry | None, expected: tuple[Change, ...]) -> None:
    world = make_world(user=user, home=home)

    changes = world.ensure()

    assert changes == expected
    assert_converged(world)


def test_applying_twice_yields_the_same_state() -> None:
    world = make_world()
    world.ensure()
    after_first = world.state()

    world.ensure()

    assert world.state() == after_first


@pytest.mark.parametrize("successes", [0, 1, 2])
def test_resumes_after_an_interruption_at_every_step(successes: int) -> None:
    reference = make_world()
    reference.ensure()

    world = make_world()
    world.journal.fail_after(successes)
    with pytest.raises(InjectedFaultError):
        world.ensure()
    world.ensure()

    assert world.state() == reference.state()
    assert world.ensure() == ()


users_before = st.none() | st.builds(
    User,
    name=st.just(SPEC.name),
    uid=st.integers(1000, 60000),
    gid=st.integers(1000, 60000),
    home=st.sampled_from([HOME, OLD_HOME]),
    shell=st.sampled_from([NOLOGIN, BASH]),
)
homes_before = st.none() | st.builds(
    Entry,
    kind=st.just(EntryKind.DIRECTORY),
    uid=st.integers(0, 60000),
    gid=st.integers(0, 60000),
    mode=st.integers(0, 0o7777),
)


@given(user=users_before, home=homes_before, successes=st.integers(0, 3))
def test_converges_from_any_state_through_any_interruption(
    user: User | None, home: Entry | None, successes: int
) -> None:
    reference = make_world(user=user, home=home)
    reference.ensure()

    world = make_world(user=user, home=home)
    world.journal.fail_after(successes)
    with contextlib.suppress(InjectedFaultError):
        world.ensure()
    world.journal.disarm()
    world.ensure()

    assert world.state() == reference.state()
    assert_converged(world)
    assert world.ensure() == ()


def test_refuses_a_home_path_that_is_not_a_directory() -> None:
    world = make_world(home=Entry(EntryKind.FILE, uid=0, gid=0, mode=0o644))
    before = world.state()

    with pytest.raises(PathConflictError, match="/home/acct1"):
        world.ensure()

    assert world.journal.mutations == []
    assert world.state() == before


def test_refuses_a_missing_parent_before_changing_anything() -> None:
    journal = Journal()
    world = World(journal, FakeUsers(journal), FakeFilesystem(journal))

    with pytest.raises(PathConflictError, match=r"^/home is not an existing directory$"):
        world.ensure()

    assert journal.mutations == []
    assert world.users.get(SPEC.name) is None


def test_refuses_a_parent_that_is_not_a_directory_before_changing_anything() -> None:
    journal = Journal()
    world = World(journal, FakeUsers(journal), FakeFilesystem(journal))
    world.filesystem.put(HOME.parent, Entry(EntryKind.FILE, uid=0, gid=0, mode=0o644))

    with pytest.raises(PathConflictError, match=r"^/home is not an existing directory$"):
        world.ensure()

    assert journal.mutations == []
    assert world.users.get(SPEC.name) is None


@pytest.mark.parametrize(
    ("name", "home", "shell", "home_mode"),
    [
        pytest.param("acct1", PurePosixPath("home/acct1"), NOLOGIN, 0o750, id="relative-home"),
        pytest.param("acct1", PurePosixPath("/home/../etc"), NOLOGIN, 0o750, id="home-with-dotdot"),
        pytest.param("acct1", PurePosixPath("/"), NOLOGIN, 0o750, id="root-as-home"),
        pytest.param("acct1", PurePosixPath("//"), NOLOGIN, 0o750, id="double-slash-root-as-home"),
        pytest.param("acct1", PurePosixPath("//etc"), NOLOGIN, 0o750, id="double-slash-home"),
        pytest.param("acct1", PurePosixPath("/home/a:b"), NOLOGIN, 0o750, id="home-with-colon"),
        pytest.param("acct1", PurePosixPath("/home/a\nb"), NOLOGIN, 0o750, id="home-with-newline"),
        pytest.param("acct1", PurePosixPath("/home/a\0b"), NOLOGIN, 0o750, id="home-with-nul"),
        pytest.param("acct1", HOME, PurePosixPath("bin/bash"), 0o750, id="relative-shell"),
        pytest.param(
            "acct1", HOME, PurePosixPath("/bin/../bin/bash"), 0o750, id="shell-with-dotdot"
        ),
        pytest.param("acct1", HOME, PurePosixPath("/"), 0o750, id="root-as-shell"),
        pytest.param("acct1", HOME, PurePosixPath("//bin/bash"), 0o750, id="double-slash-shell"),
        pytest.param("acct1", HOME, PurePosixPath("/bin/ba:sh"), 0o750, id="shell-with-colon"),
        pytest.param("", HOME, NOLOGIN, 0o750, id="empty-name"),
        pytest.param("-o root", HOME, NOLOGIN, 0o750, id="name-starting-with-dash"),
        pytest.param("a:b", HOME, NOLOGIN, 0o750, id="name-with-colon"),
        pytest.param("a\nb", HOME, NOLOGIN, 0o750, id="name-with-newline"),
        pytest.param("acct1", HOME, NOLOGIN, -1, id="negative-mode"),
        pytest.param("acct1", HOME, NOLOGIN, 0o10000, id="mode-above-range"),
    ],
)
def test_spec_rejects_hostile_values(
    name: str, home: PurePosixPath, shell: PurePosixPath, home_mode: int
) -> None:
    with pytest.raises(ValueError, match="must"):
        UserSpec(name=name, home=home, shell=shell, home_mode=home_mode)
