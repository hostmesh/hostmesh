# SPDX-License-Identifier: Apache-2.0
"""Every package must be usable under an allowed license, or carry an exception.

An exception names one package and the one license it was granted for. One
that matches nothing fails the scan: the package is gone or relicensed, and
the entry would otherwise outlive its reason.
"""

from collections.abc import Iterable
from dataclasses import dataclass

from hostmesh_hygiene.expression import InvalidExpression, satisfied
from hostmesh_hygiene.inventory import Package
from hostmesh_hygiene.policy import LicenseException, Policy, normalize_name


@dataclass(frozen=True)
class Violation:
    package: Package
    problem: str


@dataclass(frozen=True)
class ScanResult:
    checked: int
    violations: tuple[Violation, ...]
    unused_exceptions: tuple[LicenseException, ...]

    @property
    def ok(self) -> bool:
        return not self.violations and not self.unused_exceptions


def scan(packages: Iterable[Package], policy: Policy) -> ScanResult:
    exceptions = {(entry.ecosystem, entry.package): entry for entry in policy.exceptions}
    # The same package is installed in several Python environments.
    unique = {
        (package.ecosystem, normalize_name(package.name), package.version, package.license): package
        for package in packages
    }
    used: set[LicenseException] = set()
    violations: list[Violation] = []

    for key in sorted(unique):
        package = unique[key]
        problem = _problem(package, policy)
        if problem is None:
            continue
        exception = exceptions.get((package.ecosystem, normalize_name(package.name)))
        if exception is not None and exception.license == package.license:
            used.add(exception)
            continue
        if exception is not None:
            problem += f" (its exception covers {exception.license!r} only)"
        violations.append(Violation(package, problem))

    unused = tuple(entry for entry in policy.exceptions if entry not in used)
    return ScanResult(len(unique), tuple(violations), unused)


def _problem(package: Package, policy: Policy) -> str | None:
    if not package.license:
        return "declares no license"
    declared = policy.aliases.get(package.license, package.license)
    try:
        if satisfied(declared, policy.allowed):
            return None
    except InvalidExpression:
        return f"license {package.license!r} is not an SPDX expression"
    return f"license {package.license!r} is not on the allowlist"
