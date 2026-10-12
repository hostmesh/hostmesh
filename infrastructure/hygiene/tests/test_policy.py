# SPDX-License-Identifier: Apache-2.0
"""licenses.toml: the allowlist, the aliases, the exceptions and the vendored sources."""

from pathlib import Path

import pytest

from hostmesh_hygiene.policy import (
    LicenseException,
    PolicyError,
    Vendored,
    load_policy,
    normalize_name,
)

VALID = """
allowed = ["MIT", "Apache-2.0"]

[aliases]
"MIT License" = "MIT"

[[exceptions]]
ecosystem = "python"
package = "Typing_Extensions"
license = "PSF-2.0"
reason = "Permissive; no SPDX-allowlisted alternative."

[[vendored]]
name = "shadcn-vue"
license = "MIT"
source = "https://github.com/unovue/shadcn-vue"
paths = ["web/packages/ui/src/components/ui/"]
license_file = "web/packages/ui/src/components/ui/LICENSE"

[[vendored]]
name = "laravel/laravel"
license = "MIT"
source = "https://github.com/laravel/laravel"
paths = ["control-plane/artisan"]
"""


def _write(tmp_path: Path, text: str) -> Path:
    path = tmp_path / "licenses.toml"
    path.write_text(text, encoding="utf-8")
    return path


def test_a_valid_file_loads(tmp_path: Path) -> None:
    policy = load_policy(_write(tmp_path, VALID))

    assert policy.allowed == frozenset({"MIT", "Apache-2.0"})
    assert policy.aliases == {"MIT License": "MIT"}
    assert policy.exceptions == (
        LicenseException(
            "python",
            "typing-extensions",
            "PSF-2.0",
            "Permissive; no SPDX-allowlisted alternative.",
        ),
    )
    assert policy.vendored == (
        Vendored(
            "shadcn-vue",
            "MIT",
            "https://github.com/unovue/shadcn-vue",
            ("web/packages/ui/src/components/ui/",),
            "web/packages/ui/src/components/ui/LICENSE",
        ),
        Vendored(
            "laravel/laravel",
            "MIT",
            "https://github.com/laravel/laravel",
            ("control-plane/artisan",),
            None,
        ),
    )


def test_exceptions_and_vendored_sources_are_optional(tmp_path: Path) -> None:
    policy = load_policy(_write(tmp_path, 'allowed = ["MIT"]\n'))

    assert policy.aliases == {}
    assert policy.exceptions == ()
    assert policy.vendored == ()


@pytest.mark.parametrize(
    ("name", "normalised"),
    [("Typing_Extensions", "typing-extensions"), ("a.b__c", "a-b-c"), ("@Scope/Pkg", "@scope/pkg")],
)
def test_package_names_are_compared_normalised(name: str, normalised: str) -> None:
    assert normalize_name(name) == normalised


@pytest.mark.parametrize(
    ("text", "message"),
    [
        ("", "allowed"),
        ("allowed = []", "allowed"),
        ('allowed = "MIT"', "allowed"),
        ('allowed = ["MIT", 3]', "allowed"),
        ('allowed = ["MIT"]\n[aliases]\n"x" = 3', "aliases"),
        (
            'allowed = ["MIT"]\n[[exceptions]]\necosystem = "npm"\npackage = "x"\nlicense = "Y"',
            "reason",
        ),
        (
            'allowed = ["MIT"]\n[[exceptions]]\necosystem = "npm"\npackage = "x"\n'
            'license = "Y"\nreason = "  "',
            "reason",
        ),
        (
            'allowed = ["MIT"]\n[[exceptions]]\necosystem = "cargo"\npackage = "x"\n'
            'license = "Y"\nreason = "r"',
            "ecosystem",
        ),
        (
            'allowed = ["MIT"]\n'
            '[[exceptions]]\necosystem = "npm"\npackage = "x"\nlicense = "Y"\nreason = "r"\n'
            '[[exceptions]]\necosystem = "npm"\npackage = "X"\nlicense = "Z"\nreason = "r"',
            "twice",
        ),
        (
            'allowed = ["MIT"]\n[[vendored]]\nname = "x"\nlicense = "MIT"\nsource = "s"',
            "paths",
        ),
        ('allowed = ["MIT"]\nallowlist = ["ISC"]', "allowlist"),
    ],
)
def test_a_malformed_file_is_refused_with_the_key_it_trips_on(
    tmp_path: Path, text: str, message: str
) -> None:
    with pytest.raises(PolicyError, match=message):
        load_policy(_write(tmp_path, text))


def test_a_file_that_is_not_toml_is_refused(tmp_path: Path) -> None:
    with pytest.raises(PolicyError, match=r"licenses\.toml"):
        load_policy(_write(tmp_path, "allowed = ["))
