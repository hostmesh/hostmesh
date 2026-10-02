# SPDX-License-Identifier: Apache-2.0
"""ensure_user: a Linux user and its home directory (spec section 7).

The worked example of an ``ensure_*`` operation: it observes, changes only
what differs, never deletes, and can be run again after any interruption.
"""

import enum
from dataclasses import dataclass
from pathlib import PurePosixPath

from agent.services.filesystem.interface import EntryKind, Filesystem
from agent.services.users.interface import Users

_MAX_MODE = 0o7777
_ROOT = PurePosixPath("/")


class PathConflictError(Exception):
    """The home path exists and is not a directory. Nothing was changed."""


class Change(enum.Enum):
    USER_CREATED = "user_created"
    USER_MODIFIED = "user_modified"
    HOME_CREATED = "home_created"
    HOME_OWNER_SET = "home_owner_set"
    HOME_MODE_SET = "home_mode_set"


@dataclass(frozen=True, slots=True)
class UserSpec:
    """The desired user: its name, home directory, login shell and home permissions."""

    name: str
    home: PurePosixPath
    shell: PurePosixPath
    home_mode: int

    def __post_init__(self) -> None:
        for label, path in (("home", self.home), ("shell", self.shell)):
            if not path.is_absolute() or ".." in path.parts:
                raise ValueError(f"{label} must be an absolute path without '..': {path}")
        if self.home == _ROOT:
            raise ValueError("home must not be the root directory")
        if not 0 <= self.home_mode <= _MAX_MODE:
            raise ValueError(f"home_mode must be between 0 and 0o7777: {self.home_mode}")


def ensure_user(spec: UserSpec, *, users: Users, filesystem: Filesystem) -> tuple[Change, ...]:
    """Make the user and its home directory match ``spec``.

    Returns the changes made, in order, and ``()`` when nothing differed.
    Raises ``PathConflictError`` before changing anything if the home path
    exists and is not a directory.
    """
    entry = filesystem.stat(spec.home)
    if entry is not None and entry.kind is not EntryKind.DIRECTORY:
        raise PathConflictError(str(spec.home))

    changes: list[Change] = []

    user = users.get(spec.name)
    if user is None:
        user = users.create(spec.name, home=spec.home, shell=spec.shell)
        changes.append(Change.USER_CREATED)
    elif (user.home, user.shell) != (spec.home, spec.shell):
        user = users.modify(spec.name, home=spec.home, shell=spec.shell)
        changes.append(Change.USER_MODIFIED)

    if entry is None:
        filesystem.make_directory(spec.home, mode=spec.home_mode)
        changes.append(Change.HOME_CREATED)
        # make_directory's contract: owned by root, with exactly this mode.
        owner, mode = (0, 0), spec.home_mode
    else:
        owner, mode = (entry.uid, entry.gid), entry.mode

    if owner != (user.uid, user.gid):
        filesystem.set_owner(spec.home, uid=user.uid, gid=user.gid)
        changes.append(Change.HOME_OWNER_SET)
    if mode != spec.home_mode:
        filesystem.set_mode(spec.home, mode=spec.home_mode)
        changes.append(Change.HOME_MODE_SET)

    return tuple(changes)
