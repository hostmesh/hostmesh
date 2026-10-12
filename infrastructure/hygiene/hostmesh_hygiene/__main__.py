# SPDX-License-Identifier: Apache-2.0
"""Command line of the hygiene checks: `python -m hostmesh_hygiene <command>`."""

import argparse
import sys
from collections.abc import Sequence
from pathlib import Path

from hostmesh_hygiene.inventory import (
    InventoryError,
    Package,
    composer_packages,
    npm_packages,
    python_packages,
)
from hostmesh_hygiene.policy import Policy, PolicyError, load_policy
from hostmesh_hygiene.scan import scan

HERE = Path(__file__).resolve().parents[1]
REPO_ROOT = HERE.parents[1]

# Every uv project of the repository. Each one's `make install` creates the environment.
PYTHON_PROJECTS = ("agent", "infrastructure/testing", "infrastructure/hygiene")

_README = "infrastructure/hygiene/README.md"


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="hostmesh_hygiene",
        description="License hygiene: the dependency license scan.",
    )
    commands = parser.add_subparsers(dest="command", required=True)
    licenses = commands.add_parser(
        "licenses", help="fail on a dependency whose license is not allowed"
    )
    _dependency_options(licenses)
    arguments = parser.parse_args(argv)

    try:
        match arguments.command:
            case "licenses":
                return _licenses(arguments)
    except (InventoryError, PolicyError, OSError) as error:
        print(f"hostmesh_hygiene: {error}", file=sys.stderr)
        return 2
    return 2


def _dependency_options(command: argparse.ArgumentParser) -> None:
    command.add_argument("--policy", type=Path, default=HERE / "licenses.toml")
    command.add_argument(
        "--inputs", type=Path, default=HERE / ".inputs", help="what `make collect` wrote"
    )
    command.add_argument("--repo-root", type=Path, default=REPO_ROOT)


def _all_packages(arguments: argparse.Namespace, policy: Policy) -> list[Package]:
    root: Path = arguments.repo_root
    inputs: Path = arguments.inputs
    packages = composer_packages(inputs / "composer.json", root / "control-plane/vendor")
    packages += npm_packages(inputs / "pnpm.json", root / "web")
    for project in PYTHON_PROJECTS:
        packages += python_packages(root / project / ".venv", policy.aliases)
    return packages


def _licenses(arguments: argparse.Namespace) -> int:
    policy = load_policy(arguments.policy)
    result = scan(_all_packages(arguments, policy), policy)

    for violation in result.violations:
        package = violation.package
        print(
            f"{package.ecosystem} {package.name} {package.version}: {violation.problem}",
            file=sys.stderr,
        )
    for exception in result.unused_exceptions:
        print(
            f"unused exception: {exception.ecosystem} {exception.package} ({exception.license})",
            file=sys.stderr,
        )
    if not result.ok:
        print(
            f"License scan failed. Replace the dependency, or record an exception: {_README}",
            file=sys.stderr,
        )
        return 1
    print(
        f"License scan passed: {result.checked} packages, "
        f"{len(policy.exceptions)} of them by exception."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
