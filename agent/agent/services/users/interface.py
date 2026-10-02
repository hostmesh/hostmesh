# SPDX-License-Identifier: Apache-2.0
"""Linux user accounts, behind an interface (spec section 26)."""

from dataclasses import dataclass
from pathlib import PurePosixPath
from typing import Protocol


class UserExistsError(Exception):
    """An account with this name already exists."""


class UserNotFoundError(Exception):
    """No account has this name."""


@dataclass(frozen=True, slots=True)
class User:
    """A user account as the system reports it."""

    name: str
    uid: int
    gid: int
    home: PurePosixPath
    shell: PurePosixPath


class Users(Protocol):
    """Typed operations on Linux user accounts. No method takes a command."""

    def get(self, name: str) -> User | None:
        """Return the account called ``name``, or ``None`` if there is none."""

    def create(self, name: str, *, home: PurePosixPath, shell: PurePosixPath) -> User:
        """Create an account with its own primary group and system-assigned ids.

        The home directory is recorded, not created. Raises ``UserExistsError``
        if the name is taken.
        """

    def modify(self, name: str, *, home: PurePosixPath, shell: PurePosixPath) -> User:
        """Set the home path and login shell of an account. No files are moved.

        Raises ``UserNotFoundError`` if there is no such account.
        """
