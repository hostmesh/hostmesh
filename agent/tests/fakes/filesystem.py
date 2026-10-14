# SPDX-License-Identifier: Apache-2.0
"""In-memory ``Filesystem`` for unit tests."""

from dataclasses import replace
from pathlib import PurePosixPath

from agent.services.filesystem.interface import Entry, EntryKind, SymlinkError
from tests.fakes.journal import Journal

_SERVICE = "filesystem"
_ROOT = PurePosixPath("/")


class FakeFilesystem:
    """Keeps entries in memory. Starts with ``/`` as a root-owned directory."""

    def __init__(self, journal: Journal | None = None) -> None:
        self.journal = journal if journal is not None else Journal()
        self._entries: dict[PurePosixPath, Entry] = {
            _ROOT: Entry(EntryKind.DIRECTORY, uid=0, gid=0, mode=0o755),
        }

    def stat(self, path: PurePosixPath) -> Entry | None:
        self._refuse_links_above(path)
        return self._entries.get(path)

    def make_directory(self, path: PurePosixPath, *, mode: int) -> None:
        self._refuse_links_above(path)
        if path in self._entries:
            raise FileExistsError(str(path))
        parent = self._entries.get(path.parent)
        if parent is None:
            raise FileNotFoundError(str(path.parent))
        if parent.kind is not EntryKind.DIRECTORY:
            raise NotADirectoryError(str(path.parent))
        self.journal.record(_SERVICE, "make_directory", str(path))
        self._entries[path] = Entry(EntryKind.DIRECTORY, uid=0, gid=0, mode=mode)

    def set_owner(self, path: PurePosixPath, *, uid: int, gid: int) -> None:
        entry = self._existing(path)
        self.journal.record(_SERVICE, "set_owner", str(path))
        self._entries[path] = replace(entry, uid=uid, gid=gid)

    def set_mode(self, path: PurePosixPath, *, mode: int) -> None:
        entry = self._existing(path)
        self.journal.record(_SERVICE, "set_mode", str(path))
        self._entries[path] = replace(entry, mode=mode)

    def put(self, path: PurePosixPath, entry: Entry) -> None:
        """Arrange state for a test. Not journaled, and the parent is not checked."""
        self._entries[path] = entry

    def snapshot(self) -> dict[PurePosixPath, Entry]:
        """Return a copy of every entry, for state comparisons."""
        return dict(self._entries)

    def _existing(self, path: PurePosixPath) -> Entry:
        self._refuse_links_above(path)
        entry = self._entries.get(path)
        if entry is None:
            raise FileNotFoundError(str(path))
        if entry.kind is EntryKind.SYMLINK:
            raise SymlinkError(str(path))
        return entry

    def _refuse_links_above(self, path: PurePosixPath) -> None:
        """Raise ``SymlinkError`` if any directory above ``path`` is a link."""
        for ancestor in reversed(path.parents):
            entry = self._entries.get(ancestor)
            if entry is not None and entry.kind is EntryKind.SYMLINK:
                raise SymlinkError(str(ancestor))
