# SPDX-License-Identifier: Apache-2.0
"""Behavior every ``Filesystem`` implementation must show.

Subclass ``FilesystemContract`` in a ``Test*`` class and override the
``filesystem``, ``base`` and ``link`` fixtures. ``base`` is an existing
directory the implementation may write below. ``link`` places a symbolic link.
"""

from collections.abc import Callable
from pathlib import PurePosixPath

import pytest

from agent.services.filesystem.interface import Entry, EntryKind, Filesystem, SymlinkError

# Places a symbolic link at the first path, pointing to the second.
Link = Callable[[PurePosixPath, PurePosixPath], None]

# Where a link sits in the path an operation is given: the path itself, its
# parent, or a directory further up.
THROUGH_A_LINK = ["parent", "ancestor"]
ANYWHERE = ["path", *THROUGH_A_LINK]


def _path_through_link(base: PurePosixPath, link: Link, position: str, name: str) -> PurePosixPath:
    """Place a link, and return a path that would lead to ``base/outside/name`` through it."""
    outside = base / "outside"
    match position:
        case "path":
            link(base / "link", outside / name)
            return base / "link"
        case "parent":
            link(base / "link", outside)
            return base / "link" / name
        case _:
            link(base / "link", base)
            return base / "link" / "outside" / name


class FilesystemContract:
    @pytest.fixture
    def filesystem(self) -> Filesystem:
        raise NotImplementedError

    @pytest.fixture
    def base(self) -> PurePosixPath:
        raise NotImplementedError

    @pytest.fixture
    def link(self, filesystem: Filesystem) -> Link:
        raise NotImplementedError

    @pytest.fixture
    def outside(self, filesystem: Filesystem, base: PurePosixPath) -> Entry:
        """``base/outside/site``, where a followed link would lead. Returns its entry."""
        filesystem.make_directory(base / "outside", mode=0o755)
        filesystem.make_directory(base / "outside" / "site", mode=0o750)
        entry = filesystem.stat(base / "outside" / "site")
        assert entry is not None
        return entry

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

    def test_stat_reports_a_link_without_following_it(
        self, filesystem: Filesystem, base: PurePosixPath, link: Link, outside: Entry
    ) -> None:
        path = _path_through_link(base, link, "path", "site")

        entry = filesystem.stat(path)

        assert entry is not None
        assert entry.kind is EntryKind.SYMLINK

    @pytest.mark.parametrize("position", THROUGH_A_LINK)
    def test_stat_refuses_a_path_through_a_link(
        self, filesystem: Filesystem, base: PurePosixPath, link: Link, outside: Entry, position: str
    ) -> None:
        path = _path_through_link(base, link, position, "site")

        with pytest.raises(SymlinkError):
            filesystem.stat(path)

    def test_make_directory_refuses_an_existing_link(
        self, filesystem: Filesystem, base: PurePosixPath, link: Link, outside: Entry
    ) -> None:
        path = _path_through_link(base, link, "path", "new")

        with pytest.raises(FileExistsError):
            filesystem.make_directory(path, mode=0o750)

        assert filesystem.stat(base / "outside" / "new") is None

    @pytest.mark.parametrize("position", THROUGH_A_LINK)
    def test_make_directory_refuses_a_path_through_a_link(
        self, filesystem: Filesystem, base: PurePosixPath, link: Link, outside: Entry, position: str
    ) -> None:
        path = _path_through_link(base, link, position, "new")

        with pytest.raises(SymlinkError):
            filesystem.make_directory(path, mode=0o750)

        assert filesystem.stat(base / "outside" / "new") is None

    @pytest.mark.parametrize("position", ANYWHERE)
    def test_set_owner_refuses_a_link_anywhere_in_the_path(
        self, filesystem: Filesystem, base: PurePosixPath, link: Link, outside: Entry, position: str
    ) -> None:
        path = _path_through_link(base, link, position, "site")

        with pytest.raises(SymlinkError):
            filesystem.set_owner(path, uid=1000, gid=1000)

        assert filesystem.stat(base / "outside" / "site") == outside

    @pytest.mark.parametrize("position", ANYWHERE)
    def test_set_mode_refuses_a_link_anywhere_in_the_path(
        self, filesystem: Filesystem, base: PurePosixPath, link: Link, outside: Entry, position: str
    ) -> None:
        path = _path_through_link(base, link, position, "site")

        with pytest.raises(SymlinkError):
            filesystem.set_mode(path, mode=0o777)

        assert filesystem.stat(base / "outside" / "site") == outside
