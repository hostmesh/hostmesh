# SPDX-License-Identifier: Apache-2.0
"""The mutation journal the fakes share."""

import pytest

from tests.fakes.journal import InjectedFaultError, Journal, Mutation


def test_records_mutations_in_order() -> None:
    journal = Journal()

    journal.record("users", "create", "acct1")
    journal.record("filesystem", "make_directory", "/home/acct1")

    assert journal.mutations == [
        Mutation("users", "create", "acct1"),
        Mutation("filesystem", "make_directory", "/home/acct1"),
    ]


def test_fail_after_fails_one_mutation_and_then_recovers() -> None:
    journal = Journal()
    journal.fail_after(1)

    journal.record("users", "create", "first")
    with pytest.raises(InjectedFaultError, match=r"users\.create\(second\)"):
        journal.record("users", "create", "second")
    journal.record("users", "create", "third")

    assert [mutation.target for mutation in journal.mutations] == ["first", "third"]


def test_disarm_cancels_a_pending_fault() -> None:
    journal = Journal()
    journal.fail_after(0)

    journal.disarm()
    journal.record("users", "create", "acct1")

    assert journal.mutations == [Mutation("users", "create", "acct1")]
