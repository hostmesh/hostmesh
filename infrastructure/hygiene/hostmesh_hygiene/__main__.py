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
    exported_names,
    npm_packages,
    python_packages,
)
from hostmesh_hygiene.notices import render
from hostmesh_hygiene.policy import Policy, PolicyError, load_policy, normalize_name
from hostmesh_hygiene.scan import scan
from hostmesh_hygiene.spdx import SpdxConfigError, check, load_config, tracked_files

HERE = Path(__file__).resolve().parents[1]
REPO_ROOT = HERE.parents[1]

# Every uv project of the repository. Each one's `make install` creates the environment.
PYTHON_PROJECTS = ("agent", "infrastructure/testing", "infrastructure/hygiene")

_README = "infrastructure/hygiene/README.md"


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="hostmesh_hygiene",
        description="License hygiene: the dependency license scan, THIRD_PARTY_NOTICES "
        "and SPDX identifiers.",
    )
    commands = parser.add_subparsers(dest="command", required=True)
    licenses = commands.add_parser(
        "licenses", help="fail on a dependency whose license is not allowed"
    )
    _dependency_options(licenses)
    notices = commands.add_parser(
        "notices", help="write THIRD_PARTY_NOTICES from the production dependencies"
    )
    _dependency_options(notices)
    notices.add_argument("--output", type=Path, default=REPO_ROOT / "THIRD_PARTY_NOTICES")
    notices.add_argument(
        "--check", action="store_true", help="write nothing; fail when the file is out of date"
    )
    spdx = commands.add_parser("spdx", help="fail on a source file without an SPDX identifier")
    spdx.add_argument("--config", type=Path, default=HERE / "spdx.toml")
    spdx.add_argument("--repo-root", type=Path, default=REPO_ROOT)
    arguments = parser.parse_args(argv)

    try:
        match arguments.command:
            case "licenses":
                return _licenses(arguments)
            case "notices":
                return _notices(arguments)
            case "spdx":
                return _spdx(arguments)
    except (InventoryError, PolicyError, SpdxConfigError, OSError) as error:
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


def _production_packages(arguments: argparse.Namespace, policy: Policy) -> list[Package]:
    """What a build or an installation includes: no development dependencies."""
    root: Path = arguments.repo_root
    inputs: Path = arguments.inputs
    packages = composer_packages(inputs / "composer-prod.json", root / "control-plane/vendor")
    packages += npm_packages(inputs / "pnpm-prod.json", root / "web")

    # Only the agent ships. Its environment also holds its development tools.
    shipped = exported_names(inputs / "python-prod.txt")
    installed = {
        normalize_name(package.name): package
        for package in python_packages(root / "agent/.venv", policy.aliases)
    }
    missing = sorted(shipped - installed.keys())
    if missing:
        raise InventoryError(
            f"{root / 'agent/.venv'}: not installed: {', '.join(missing)} "
            "(run `make -C agent install`)"
        )
    packages += [installed[name] for name in sorted(shipped)]
    return packages


def _notices(arguments: argparse.Namespace) -> int:
    policy = load_policy(arguments.policy)
    text = render(_production_packages(arguments, policy), policy.vendored, arguments.repo_root)
    output: Path = arguments.output

    if not arguments.check:
        output.write_text(text, encoding="utf-8")
        print(f"Wrote {output.name}.")
        return 0
    if output.is_file() and output.read_text(encoding="utf-8") == text:
        print(f"{output.name} is up to date.")
        return 0
    print(
        f"{output.name} is out of date. Run `make -C infrastructure/hygiene notices` "
        "and commit the result.",
        file=sys.stderr,
    )
    return 1


def _spdx(arguments: argparse.Namespace) -> int:
    config = load_config(arguments.config)
    files = tracked_files(arguments.repo_root)
    problems = check(files, arguments.repo_root, config)
    for problem in problems:
        print(problem, file=sys.stderr)
    if problems:
        print(
            f"SPDX check failed: {len(problems)} problem(s). Add the identifier in the "
            f"language's comment style, or see {_README}.",
            file=sys.stderr,
        )
        return 1
    print(f"SPDX check passed: {len(files)} tracked files.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
