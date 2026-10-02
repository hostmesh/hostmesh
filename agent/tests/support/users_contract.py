# SPDX-License-Identifier: Apache-2.0
"""Behavior every ``Users`` implementation must show.

Subclass ``UsersContract`` in a ``Test*`` class and override the ``users``
fixture. The fake runs it as a unit test; the real implementation runs the
same tests on a Debian node, which keeps the fake honest.
"""

from dataclasses import replace
from pathlib import PurePosixPath

import pytest

from agent.services.users.interface import UserExistsError, UserNotFoundError, Users

HOME = PurePosixPath("/home/acct1")
SHELL = PurePosixPath("/usr/sbin/nologin")


class UsersContract:
    @pytest.fixture
    def users(self) -> Users:
        raise NotImplementedError

    def test_get_returns_none_for_an_unknown_user(self, users: Users) -> None:
        assert users.get("acct1") is None

    def test_create_returns_the_account_and_get_finds_it(self, users: Users) -> None:
        created = users.create("acct1", home=HOME, shell=SHELL)

        assert (created.name, created.home, created.shell) == ("acct1", HOME, SHELL)
        assert users.get("acct1") == created

    def test_create_gives_each_account_its_own_ids(self, users: Users) -> None:
        first = users.create("acct1", home=HOME, shell=SHELL)
        second = users.create("acct2", home=PurePosixPath("/home/acct2"), shell=SHELL)

        assert first.uid != second.uid
        assert first.gid != second.gid

    def test_create_refuses_an_existing_name(self, users: Users) -> None:
        users.create("acct1", home=HOME, shell=SHELL)

        with pytest.raises(UserExistsError):
            users.create("acct1", home=HOME, shell=SHELL)

    def test_modify_sets_home_and_shell_and_keeps_the_ids(self, users: Users) -> None:
        created = users.create("acct1", home=HOME, shell=SHELL)
        moved = PurePosixPath("/home/moved")
        bash = PurePosixPath("/bin/bash")

        modified = users.modify("acct1", home=moved, shell=bash)

        assert modified == replace(created, home=moved, shell=bash)
        assert users.get("acct1") == modified

    def test_modify_refuses_an_unknown_user(self, users: Users) -> None:
        with pytest.raises(UserNotFoundError):
            users.modify("ghost", home=HOME, shell=SHELL)
