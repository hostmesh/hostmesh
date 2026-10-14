# SPDX-License-Identifier: Apache-2.0
"""Directories, ownership and permissions, behind an interface (spec section 26).

No operation follows a symbolic link, in any position of the path. A link
inside a customer's home could otherwise point an operation the agent runs as
root at a path outside it. A real implementation resolves the path one
component at a time without following links (``O_NOFOLLOW`` on each, or
``openat2`` with ``RESOLVE_NO_SYMLINKS``), never with a plain ``chown`` or
``chmod`` of the whole path.
"""

import enum
from dataclasses import dataclass
from pathlib import PurePosixPath
from typing import Protocol


class EntryKind(enum.Enum):
    DIRECTORY = "directory"
    FILE = "file"
    SYMLINK = "symlink"


class SymlinkError(OSError):
    """The path is, or runs through, a symbolic link. Nothing was changed."""


@dataclass(frozen=True, slots=True)
class Entry:
    """What exists at a path: its kind, owner and permission bits."""

    kind: EntryKind
    uid: int
    gid: int
    mode: int


class Filesystem(Protocol):
    """Typed filesystem operations. No method takes a command or follows a link."""

    def stat(self, path: PurePosixPath) -> Entry | None:
        """Return what exists at ``path``, or ``None`` if nothing does.

        A link at ``path`` is reported as itself, with the kind ``SYMLINK``.
        Raises ``SymlinkError`` if a directory above ``path`` is a link.
        """

    def make_directory(self, path: PurePosixPath, *, mode: int) -> None:
        """Create a directory owned by root with exactly ``mode``.

        Ownership is set separately with ``set_owner``, as on a real system.
        Raises ``FileExistsError`` if the path exists, a link included,
        ``FileNotFoundError`` if the parent is missing, ``NotADirectoryError``
        if the parent is not a directory and ``SymlinkError`` if a directory
        above ``path`` is a link.
        """

    def set_owner(self, path: PurePosixPath, *, uid: int, gid: int) -> None:
        """Set the owner and group of ``path``.

        Raises ``FileNotFoundError`` if it is missing and ``SymlinkError`` if
        ``path`` or a directory above it is a link.
        """

    def set_mode(self, path: PurePosixPath, *, mode: int) -> None:
        """Set the permission bits of ``path``.

        Raises ``FileNotFoundError`` if it is missing and ``SymlinkError`` if
        ``path`` or a directory above it is a link.
        """
