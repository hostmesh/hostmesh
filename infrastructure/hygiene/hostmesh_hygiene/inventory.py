# SPDX-License-Identifier: Apache-2.0
"""What each ecosystem's own tool says is installed, as Package records.

An input that is missing or lists nothing is an error: a scan of no
packages would pass, and prove nothing.
"""

import json
import re
from collections.abc import Iterable, Mapping
from dataclasses import dataclass
from email import message_from_string
from pathlib import Path

from hostmesh_hygiene.expression import InvalidExpression, satisfied
from hostmesh_hygiene.policy import normalize_name

_REQUIREMENT = re.compile(r"^([A-Za-z0-9][A-Za-z0-9._-]*)==")


class InventoryError(ValueError):
    """A listing is missing, unreadable or empty."""


@dataclass(frozen=True)
class Package:
    ecosystem: str
    name: str
    version: str
    # An SPDX expression where the package declares one; "" when it declares nothing.
    license: str
    # The directory that holds the package's license files.
    path: Path | None = None


def composer_packages(listing: Path, vendor: Path) -> list[Package]:
    """`composer licenses --format=json`. Several licenses mean a choice (Composer's rule)."""
    data = _load(listing)
    dependencies = data.get("dependencies") if isinstance(data, dict) else None
    if not isinstance(dependencies, dict) or not dependencies:
        raise InventoryError(f"{listing}: no Composer packages listed")
    return _sorted(
        Package(
            "composer",
            name,
            str(entry.get("version", "")),
            " OR ".join(entry.get("license") or []),
            vendor / name,
        )
        for name, entry in dependencies.items()
    )


def npm_packages(listing: Path, web_root: Path) -> list[Package]:
    """`pnpm --recursive licenses list --json`, an object keyed by license."""
    data = _load(listing)
    if not isinstance(data, dict) or not data:
        raise InventoryError(f"{listing}: no npm packages listed")
    packages: list[Package] = []
    for entries in data.values():
        if not isinstance(entries, list):
            raise InventoryError(f"{listing}: not the output of pnpm licenses list --json")
        for entry in entries:
            declared = str(entry.get("license") or "")
            paths = sorted(entry.get("paths") or [])
            packages.append(
                Package(
                    "npm",
                    str(entry["name"]),
                    ", ".join(entry.get("versions") or []),
                    "" if declared == "Unknown" else declared,
                    _below(web_root, paths[-1]) if paths else None,
                )
            )
    if not packages:
        raise InventoryError(f"{listing}: no npm packages listed")
    return _sorted(packages)


def python_packages(venv: Path, aliases: Mapping[str, str]) -> list[Package]:
    """The distributions installed in a virtual environment."""
    packages: list[Package] = []
    for metadata in venv.glob("lib/python*/site-packages/*.dist-info/METADATA"):
        message = message_from_string(metadata.read_text(encoding="utf-8", errors="replace"))
        classifiers = [
            value.rsplit(" :: ", 1)[-1]
            for value in message.get_all("Classifier", [])
            if str(value).startswith("License ::")
        ]
        packages.append(
            Package(
                "python",
                str(message["Name"]),
                str(message["Version"]),
                str(message.get("License-Expression") or "").strip()
                or _expression(str(message.get("License") or ""))
                or " AND ".join(aliases.get(name, name) for name in classifiers),
                metadata.parent,
            )
        )
    if not packages:
        raise InventoryError(f"{venv}: no installed Python packages (run its `make install`)")
    return _sorted(packages)


def exported_names(requirements: Path) -> frozenset[str]:
    """The package names in the output of `uv export --no-hashes`."""
    try:
        lines = requirements.read_text(encoding="utf-8").splitlines()
    except OSError as error:
        raise InventoryError(f"{requirements}: {error.strerror}") from error
    names = frozenset(
        normalize_name(match.group(1)) for line in lines if (match := _REQUIREMENT.match(line))
    )
    if not names:
        raise InventoryError(f"{requirements}: no packages listed")
    return names


def _load(listing: Path) -> object:
    try:
        return json.loads(listing.read_text(encoding="utf-8"))
    except OSError as error:
        raise InventoryError(f"{listing}: {error.strerror}") from error
    except json.JSONDecodeError as error:
        raise InventoryError(f"{listing}: not JSON ({error.msg})") from error


def _below(web_root: Path, path: str) -> Path:
    """pnpm prints the paths of the machine it ran on; keep what is below the workspace."""
    _, marker, rest = path.partition("node_modules/")
    return web_root / "node_modules" / rest if marker else Path(path)


def _expression(declared: str) -> str:
    """The free-text License header, when it happens to be an SPDX expression."""
    text = declared.strip()
    if "\n" in text:
        return ""
    try:
        satisfied(text, ())
    except InvalidExpression:
        return ""
    return text


def _sorted(packages: Iterable[Package]) -> list[Package]:
    return sorted(packages, key=lambda package: (package.name.lower(), package.version))
