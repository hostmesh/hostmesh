# SPDX-License-Identifier: Apache-2.0
"""Behavior every ``Filesystem`` implementation must show.

Subclass ``FilesystemContract`` in a ``Test*`` class and override the
``filesystem`` and ``base`` fixtures. ``base`` is an existing directory the
implementation may write below.
"""

from pathlib import PurePosixPath

import pytest

from agent.services.filesystem.interface import Entry, EntryKind, Filesystem


class FilesystemContract:
    @pytest.fixture
    def filesystem(self) -> Filesystem:
        raise NotImplementedError

    @pytest.fixture
    def base(self) -> PurePosixPath:
        raise NotImplementedError

    def test_stat_returns_none_for_a_missing_path(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        assert filesystem.stat(base / "site") is None

    def test_make_directory_creates_a_root_owned_directory_with_the_mode(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        filesystem.make_directory(base / "site", mode=0o750)

        assert filesystem.stat(base / "site") == Entry(
            EntryKind.DIRECTORY, uid=0, gid=0, mode=0o750
        )

    def test_make_directory_refuses_an_existing_path(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        filesystem.make_directory(base / "site", mode=0o750)

        with pytest.raises(FileExistsError):
            filesystem.make_directory(base / "site", mode=0o750)

    def test_make_directory_refuses_a_missing_parent(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        with pytest.raises(FileNotFoundError):
            filesystem.make_directory(base / "missing" / "site", mode=0o750)

    def test_set_owner_changes_the_ids_and_keeps_the_mode(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        filesystem.make_directory(base / "site", mode=0o750)

        filesystem.set_owner(base / "site", uid=1000, gid=1001)

        assert filesystem.stat(base / "site") == Entry(
            EntryKind.DIRECTORY, uid=1000, gid=1001, mode=0o750
        )

    def test_set_mode_changes_the_mode_and_keeps_the_owner(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        filesystem.make_directory(base / "site", mode=0o750)
        filesystem.set_owner(base / "site", uid=1000, gid=1001)

        filesystem.set_mode(base / "site", mode=0o700)

        assert filesystem.stat(base / "site") == Entry(
            EntryKind.DIRECTORY, uid=1000, gid=1001, mode=0o700
        )

    def test_set_owner_refuses_a_missing_path(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        with pytest.raises(FileNotFoundError):
            filesystem.set_owner(base / "site", uid=1000, gid=1000)

    def test_set_mode_refuses_a_missing_path(
        self, filesystem: Filesystem, base: PurePosixPath
    ) -> None:
        with pytest.raises(FileNotFoundError):
            filesystem.set_mode(base / "site", mode=0o700)
