# SPDX-License-Identifier: Apache-2.0
"""The license policy (licenses.toml): what is allowed, and every exception with its reason."""

import re
import tomllib
from collections.abc import Mapping
from dataclasses import dataclass
from pathlib import Path
from typing import Any

ECOSYSTEMS: tuple[str, ...] = ("composer", "npm", "python")

_KEYS = frozenset({"allowed", "aliases", "exceptions", "vendored"})


class PolicyError(ValueError):
    """licenses.toml is missing a key or holds a value of the wrong shape."""


@dataclass(frozen=True)
class LicenseException:
    ecosystem: str
    package: str
    license: str
    reason: str


@dataclass(frozen=True)
class Vendored:
    """Third-party source code that lives in this repository."""

    name: str
    license: str
    source: str
    paths: tuple[str, ...]
    license_file: str | None


@dataclass(frozen=True)
class Policy:
    allowed: frozenset[str]
    aliases: Mapping[str, str]
    exceptions: tuple[LicenseException, ...]
    vendored: tuple[Vendored, ...]


def normalize_name(name: str) -> str:
    """PEP 503 normalisation. Harmless for Composer and npm names."""
    return re.sub(r"[-_.]+", "-", name).lower()


def load_policy(path: Path) -> Policy:
    try:
        data = tomllib.loads(path.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as error:
        raise PolicyError(f"{path.name}: {error}") from error

    unknown = sorted(set(data) - _KEYS)
    if unknown:
        raise PolicyError(f"{path.name}: unknown key {unknown[0]!r}")

    allowed = data.get("allowed")
    if not _strings(allowed) or not allowed:
        raise PolicyError(f"{path.name}: allowed must be a non-empty list of SPDX identifiers")

    aliases = data.get("aliases", {})
    if not isinstance(aliases, dict) or not all(isinstance(v, str) for v in aliases.values()):
        raise PolicyError(f"{path.name}: aliases must map a declared license to an SPDX expression")

    exceptions = tuple(_exception(path, entry) for entry in _tables(path, data, "exceptions"))
    seen: set[tuple[str, str]] = set()
    for exception in exceptions:
        key = (exception.ecosystem, exception.package)
        if key in seen:
            raise PolicyError(
                f"{path.name}: {exception.ecosystem} package {exception.package!r} "
                "has an exception twice"
            )
        seen.add(key)

    vendored = tuple(_vendored(path, entry) for entry in _tables(path, data, "vendored"))
    return Policy(frozenset(allowed), dict(aliases), exceptions, vendored)


def _strings(value: object) -> bool:
    return isinstance(value, list) and all(isinstance(item, str) for item in value)


def _tables(path: Path, data: Mapping[str, Any], key: str) -> list[dict[str, Any]]:
    tables = data.get(key, [])
    if not isinstance(tables, list) or not all(isinstance(table, dict) for table in tables):
        raise PolicyError(f"{path.name}: {key} must be an array of tables")
    return tables


def _text(path: Path, table: Mapping[str, Any], section: str, key: str) -> str:
    value = table.get(key)
    if not isinstance(value, str) or not value.strip():
        raise PolicyError(f"{path.name}: every {section} entry needs a non-empty {key}")
    return value.strip()


def _exception(path: Path, table: Mapping[str, Any]) -> LicenseException:
    ecosystem = _text(path, table, "exceptions", "ecosystem")
    if ecosystem not in ECOSYSTEMS:
        raise PolicyError(
            f"{path.name}: ecosystem {ecosystem!r} is not one of {', '.join(ECOSYSTEMS)}"
        )
    return LicenseException(
        ecosystem,
        normalize_name(_text(path, table, "exceptions", "package")),
        _text(path, table, "exceptions", "license"),
        _text(path, table, "exceptions", "reason"),
    )


def _vendored(path: Path, table: Mapping[str, Any]) -> Vendored:
    paths = table.get("paths")
    if not _strings(paths) or not paths:
        raise PolicyError(f"{path.name}: every vendored entry needs a non-empty list of paths")
    license_file = table.get("license_file")
    if license_file is not None and not isinstance(license_file, str):
        raise PolicyError(f"{path.name}: license_file of a vendored entry must be a path")
    return Vendored(
        _text(path, table, "vendored", "name"),
        _text(path, table, "vendored", "license"),
        _text(path, table, "vendored", "source"),
        tuple(paths),
        license_file,
    )
