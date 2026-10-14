# SPDX-License-Identifier: Apache-2.0
"""Mutation journal shared by the in-memory fakes.

Every fake records its state-changing calls here, in order. Tests read the
record to assert that a converged operation changes nothing, and arm a fault
to simulate a run that is interrupted at any point.
"""

from dataclasses import dataclass, field


class InjectedFaultError(Exception):
    """Raised by a fake in place of a mutation, to simulate an interrupted run."""


@dataclass(frozen=True, slots=True)
class Mutation:
    """One state-changing call on a fake."""

    service: str
    operation: str
    target: str


@dataclass(slots=True)
class Journal:
    """An ordered record of mutations, with one-shot fault injection."""

    mutations: list[Mutation] = field(default_factory=list)
    _successes_before_fault: int | None = field(default=None, repr=False)

    def fail_after(self, successes: int) -> None:
        """Let ``successes`` more mutations through, then fail the next one, once."""
        self._successes_before_fault = successes

    def disarm(self) -> None:
        """Cancel a fault that has not fired."""
        self._successes_before_fault = None

    def record(self, service: str, operation: str, target: str) -> None:
        """Record a mutation that is about to be applied, or raise the armed fault."""
        if self._successes_before_fault is not None:
            if self._successes_before_fault == 0:
                self._successes_before_fault = None
                raise InjectedFaultError(f"{service}.{operation}({target})")
            self._successes_before_fault -= 1
        self.mutations.append(Mutation(service, operation, target))
