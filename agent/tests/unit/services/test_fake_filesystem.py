# SPDX-License-Identifier: Apache-2.0
"""FakeFilesystem: the Filesystem contract, plus what only a fake does."""

from pathlib import PurePosixPath

import pytest

from agent.services.filesystem.fake import FakeFilesystem
from agent.services.filesystem.interface import Entry, EntryKind
from agent.services.journal import InjectedFaultError, Journal, Mutation
from agent.services.users.fake import FakeUsers
from tests.support.filesystem_contract import FilesystemContract

ROOT = PurePosixPath("/")
SITE = PurePosixPath("/site")
ROOT_DIRECTORY = Entry(EntryKind.DIRECTORY, uid=0, gid=0, mode=0o755)


class TestFakeFilesystem(FilesystemContract):
    @pytest.fixture
    def filesystem(self) -> FakeFilesystem:
        return FakeFilesystem()

    @pytest.fixture
    def base(self) -> PurePosixPath:
        return ROOT


def test_starts_with_a_root_directory() -> None:
    assert FakeFilesystem().snapshot() == {ROOT: ROOT_DIRECTORY}


def test_mutations_are_journaled_in_order_and_reads_are_not() -> None:
    filesystem = FakeFilesystem()

    filesystem.stat(SITE)
    filesystem.make_directory(SITE, mode=0o750)
    filesystem.set_owner(SITE, uid=1000, gid=1000)
    filesystem.set_mode(SITE, mode=0o700)

    assert filesystem.journal.mutations == [
        Mutation("filesystem", "make_directory", "/site"),
        Mutation("filesystem", "set_owner", "/site"),
        Mutation("filesystem", "set_mode", "/site"),
    ]


def test_a_refused_call_is_not_journaled() -> None:
    filesystem = FakeFilesystem()

    with pytest.raises(FileNotFoundError):
        filesystem.set_mode(SITE, mode=0o700)

    assert filesystem.journal.mutations == []


def test_an_injected_fault_leaves_the_state_untouched() -> None:
    filesystem = FakeFilesystem()
    filesystem.journal.fail_after(0)

    with pytest.raises(InjectedFaultError):
        filesystem.make_directory(SITE, mode=0o750)

    assert filesystem.snapshot() == {ROOT: ROOT_DIRECTORY}


def test_make_directory_refuses_a_parent_that_is_not_a_directory() -> None:
    filesystem = FakeFilesystem()
    filesystem.put(PurePosixPath("/file"), Entry(EntryKind.FILE, uid=0, gid=0, mode=0o644))

    with pytest.raises(NotADirectoryError):
        filesystem.make_directory(PurePosixPath("/file/site"), mode=0o750)


def test_put_arranges_state_without_journaling() -> None:
    filesystem = FakeFilesystem()

    filesystem.put(SITE, Entry(EntryKind.DIRECTORY, uid=1000, gid=1000, mode=0o750))

    assert filesystem.journal.mutations == []
    assert filesystem.stat(SITE) == Entry(EntryKind.DIRECTORY, uid=1000, gid=1000, mode=0o750)


def test_two_fakes_share_one_journal() -> None:
    journal = Journal()
    users = FakeUsers(journal)
    filesystem = FakeFilesystem(journal)

    users.create("acct1", home=SITE, shell=PurePosixPath("/usr/sbin/nologin"))
    filesystem.make_directory(SITE, mode=0o750)

    assert journal.mutations == [
        Mutation("users", "create", "acct1"),
        Mutation("filesystem", "make_directory", "/site"),
    ]
