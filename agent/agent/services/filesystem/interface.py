# SPDX-License-Identifier: Apache-2.0
"""Directories, ownership and permissions, behind an interface (spec section 26)."""

import enum
from dataclasses import dataclass
from pathlib import PurePosixPath
from typing import Protocol


class EntryKind(enum.Enum):
    DIRECTORY = "directory"
    FILE = "file"


@dataclass(frozen=True, slots=True)
class Entry:
    """What exists at a path: its kind, owner and permission bits."""

    kind: EntryKind
    uid: int
    gid: int
    mode: int


class Filesystem(Protocol):
    """Typed filesystem operations. No method takes a command."""

    def stat(self, path: PurePosixPath) -> Entry | None:
        """Return what exists at ``path``, or ``None`` if nothing does."""

    def make_directory(self, path: PurePosixPath, *, mode: int) -> None:
        """Create a directory owned by root with exactly ``mode``.

        Ownership is set separately with ``set_owner``, as on a real system.
        Raises ``FileExistsError`` if the path exists, ``FileNotFoundError`` if
        the parent is missing and ``NotADirectoryError`` if the parent is not a
        directory.
        """

    def set_owner(self, path: PurePosixPath, *, uid: int, gid: int) -> None:
        """Set the owner and group of ``path``. Raises ``FileNotFoundError`` if it is missing."""

    def set_mode(self, path: PurePosixPath, *, mode: int) -> None:
        """Set the permission bits of ``path``. Raises ``FileNotFoundError`` if it is missing."""
