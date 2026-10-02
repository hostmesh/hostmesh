# SPDX-License-Identifier: Apache-2.0
"""FakeUsers: the Users contract, plus what only a fake does."""

from pathlib import PurePosixPath

import pytest

from agent.services.journal import InjectedFaultError, Mutation
from agent.services.users.fake import FakeUsers
from agent.services.users.interface import User, UserExistsError
from tests.support.users_contract import UsersContract

HOME = PurePosixPath("/home/acct1")
SHELL = PurePosixPath("/usr/sbin/nologin")


class TestFakeUsers(UsersContract):
    @pytest.fixture
    def users(self) -> FakeUsers:
        return FakeUsers()


def test_mutations_are_journaled_in_order_and_reads_are_not() -> None:
    users = FakeUsers()

    users.get("acct1")
    users.create("acct1", home=HOME, shell=SHELL)
    users.modify("acct1", home=HOME, shell=PurePosixPath("/bin/bash"))

    assert users.journal.mutations == [
        Mutation("users", "create", "acct1"),
        Mutation("users", "modify", "acct1"),
    ]


def test_a_refused_call_is_not_journaled() -> None:
    users = FakeUsers()
    users.create("acct1", home=HOME, shell=SHELL)

    with pytest.raises(UserExistsError):
        users.create("acct1", home=HOME, shell=SHELL)

    assert users.journal.mutations == [Mutation("users", "create", "acct1")]


def test_an_injected_fault_leaves_the_state_untouched() -> None:
    users = FakeUsers()
    users.journal.fail_after(0)

    with pytest.raises(InjectedFaultError):
        users.create("acct1", home=HOME, shell=SHELL)

    assert users.snapshot() == {}


def test_put_arranges_state_without_journaling_and_keeps_new_ids_unique() -> None:
    users = FakeUsers()

    users.put(User("seeded", uid=1000, gid=1000, home=PurePosixPath("/home/seeded"), shell=SHELL))

    assert users.journal.mutations == []
    assert users.create("acct1", home=HOME, shell=SHELL).uid == 1001
