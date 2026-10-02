# SPDX-License-Identifier: Apache-2.0
"""In-memory ``Users`` for unit tests."""

from dataclasses import replace
from pathlib import PurePosixPath

from agent.services.journal import Journal
from agent.services.users.interface import User, UserExistsError, UserNotFoundError

_SERVICE = "users"


class FakeUsers:
    """Keeps accounts in memory and assigns ids upwards from ``first_id``."""

    def __init__(self, journal: Journal | None = None, *, first_id: int = 1000) -> None:
        self.journal = journal if journal is not None else Journal()
        self._users: dict[str, User] = {}
        self._next_id = first_id

    def get(self, name: str) -> User | None:
        return self._users.get(name)

    def create(self, name: str, *, home: PurePosixPath, shell: PurePosixPath) -> User:
        if name in self._users:
            raise UserExistsError(name)
        self.journal.record(_SERVICE, "create", name)
        user = User(name, uid=self._next_id, gid=self._next_id, home=home, shell=shell)
        self._next_id += 1
        self._users[name] = user
        return user

    def modify(self, name: str, *, home: PurePosixPath, shell: PurePosixPath) -> User:
        current = self._users.get(name)
        if current is None:
            raise UserNotFoundError(name)
        self.journal.record(_SERVICE, "modify", name)
        user = replace(current, home=home, shell=shell)
        self._users[name] = user
        return user

    def put(self, user: User) -> None:
        """Arrange state for a test. Not journaled."""
        self._users[user.name] = user
        self._next_id = max(self._next_id, user.uid + 1, user.gid + 1)

    def snapshot(self) -> dict[str, User]:
        """Return a copy of every account, for state comparisons."""
        return dict(self._users)
