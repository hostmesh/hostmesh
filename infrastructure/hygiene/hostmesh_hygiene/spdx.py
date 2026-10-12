# SPDX-License-Identifier: Apache-2.0
"""Authored source files start with an SPDX license identifier (spec section 36).

Vendor, generated, lock and third-party-derived files are excluded by an
explicit pattern with a reason, and keep whatever header they came with.
"""

import re
import subprocess
import tomllib
from collections.abc import Iterable
from dataclasses import dataclass
from fnmatch import fnmatchcase
from pathlib import Path, PurePosixPath

HEADER_LINES = 10

# The identifier ends the line, apart from the end of a block comment.
_IDENTIFIER = re.compile(r"SPDX-License-Identifier:\s*(.+?)\s*(?:-->|\*/)?\s*$")


class SpdxConfigError(ValueError):
    """spdx.toml is missing a key or holds a value of the wrong shape."""


@dataclass(frozen=True)
class Exclusion:
    # fnmatch: `*` matches `/` too, so `dir/*` covers the whole tree below dir.
    pattern: str
    reason: str


@dataclass(frozen=True)
class SpdxConfig:
    extensions: frozenset[str]
    filenames: frozenset[str]
    identifiers: frozenset[str]
    exclusions: tuple[Exclusion, ...]


def load_config(path: Path) -> SpdxConfig:
    try:
        data = tomllib.loads(path.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as error:
        raise SpdxConfigError(f"{path.name}: {error}") from error

    extensions = _strings(path, data.get("extensions"), "extensions", required=True)
    if not all(extension.startswith(".") for extension in extensions):
        raise SpdxConfigError(f"{path.name}: every entry of extensions must start with a dot")
    filenames = _strings(path, data.get("filenames", []), "filenames", required=False)
    identifiers = _strings(path, data.get("identifiers"), "identifiers", required=True)

    exclusions: list[Exclusion] = []
    for table in data.get("exclude", []):
        pattern, reason = table.get("pattern"), table.get("reason")
        if not isinstance(pattern, str) or not pattern:
            raise SpdxConfigError(f"{path.name}: every exclude entry needs a pattern")
        if not isinstance(reason, str) or not reason.strip():
            raise SpdxConfigError(f"{path.name}: the exclusion {pattern!r} needs a reason")
        exclusions.append(Exclusion(pattern, reason.strip()))
    return SpdxConfig(extensions, filenames, identifiers, tuple(exclusions))


def check(files: Iterable[str], root: Path, config: SpdxConfig) -> list[str]:
    """One line per problem; none when every file is in order."""
    problems: list[str] = []
    matched: set[Exclusion] = set()
    expected = ", ".join(sorted(config.identifiers))

    for name in sorted(files):
        exclusion = next(
            (entry for entry in config.exclusions if fnmatchcase(name, entry.pattern)), None
        )
        if exclusion is not None:
            matched.add(exclusion)
        path = PurePosixPath(name)
        required = path.suffix in config.extensions or path.name in config.filenames
        if exclusion is None and not required:
            continue
        if not (root / name).is_file():
            # Deleted in the working tree, or a submodule: nothing to read.
            continue

        identifier = _identifier(root / name)
        if exclusion is not None:
            if identifier == "Apache-2.0":
                problems.append(
                    f"{name}: excluded ({exclusion.reason}) but says Apache-2.0; "
                    "remove the header or the exclusion"
                )
        elif identifier is None:
            problems.append(f"{name}: no SPDX-License-Identifier in the first {HEADER_LINES} lines")
        elif identifier not in config.identifiers:
            problems.append(
                f"{name}: SPDX-License-Identifier is {identifier!r}, expected one of: {expected}"
            )

    problems.extend(
        f"spdx.toml: the exclusion {entry.pattern!r} matches no tracked file"
        for entry in config.exclusions
        if entry not in matched
    )
    return problems


def tracked_files(root: Path) -> list[str]:
    completed = subprocess.run(  # noqa: S603 - an argument list, never a shell
        ["git", "-C", str(root), "ls-files", "-z"],  # noqa: S607 - git from PATH, as everywhere
        check=True,
        capture_output=True,
    )
    return [name for name in completed.stdout.decode("utf-8").split("\0") if name]


def _identifier(path: Path) -> str | None:
    with path.open(encoding="utf-8", errors="replace") as handle:
        for _, line in zip(range(HEADER_LINES), handle, strict=False):
            match = _IDENTIFIER.search(line)
            if match is not None:
                return match.group(1)
    return None


def _strings(path: Path, value: object, key: str, *, required: bool) -> frozenset[str]:
    if (
        not isinstance(value, list)
        or not all(isinstance(item, str) for item in value)
        or (required and not value)
    ):
        raise SpdxConfigError(f"{path.name}: {key} must be a list of strings")
    return frozenset(value)
