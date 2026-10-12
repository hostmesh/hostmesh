# SPDX-License-Identifier: Apache-2.0
"""Packages against the policy."""

from pathlib import Path

from hostmesh_hygiene.inventory import Package
from hostmesh_hygiene.policy import LicenseException, Policy
from hostmesh_hygiene.scan import scan

BLUE_OAK = LicenseException("npm", "lru-cache", "BlueOak-1.0.0", "Permissive.")


def _policy(*exceptions: LicenseException) -> Policy:
    return Policy(
        allowed=frozenset({"MIT", "BSD-3-Clause", "MPL-2.0"}),
        aliases={"MIT License": "MIT"},
        exceptions=exceptions,
        vendored=(),
    )


def test_allowed_licenses_pass() -> None:
    result = scan(
        [
            Package("composer", "brick/math", "1.0.0", "MIT"),
            Package("composer", "nette/utils", "v4", "BSD-3-Clause OR GPL-3.0-only"),
            Package("npm", "lightningcss", "1.33.0", "MPL-2.0"),
            Package("python", "pluggy", "1.6", "MIT License"),
        ],
        _policy(),
    )

    assert result.ok
    assert result.checked == 4
    assert result.violations == ()


def test_a_license_off_the_list_is_a_violation_that_names_the_package() -> None:
    gpl = Package("composer", "acme/gpl", "1.0.0", "GPL-3.0-only")

    result = scan([Package("composer", "brick/math", "1.0.0", "MIT"), gpl], _policy())

    assert not result.ok
    assert [violation.package for violation in result.violations] == [gpl]
    assert "'GPL-3.0-only'" in result.violations[0].problem


def test_an_undeclared_license_is_a_violation() -> None:
    result = scan(
        [
            Package("npm", "mystery", "1.0.0", ""),
            Package("python", "odd", "1.0", "Other/Proprietary License"),
        ],
        _policy(),
    )

    assert [violation.problem for violation in result.violations] == [
        "declares no license",
        "license 'Other/Proprietary License' is not an SPDX expression",
    ]


def test_an_exception_lets_one_package_through() -> None:
    result = scan(
        [
            Package("npm", "lru-cache", "11.5.3", "BlueOak-1.0.0"),
            Package("npm", "minimatch", "10.2.6", "BlueOak-1.0.0"),
        ],
        _policy(BLUE_OAK),
    )

    assert [violation.package.name for violation in result.violations] == ["minimatch"]
    assert result.unused_exceptions == ()


def test_an_exception_is_matched_on_the_normalised_name_and_the_ecosystem() -> None:
    exception = LicenseException("python", "typing-extensions", "PSF-2.0", "Permissive.")

    assert scan([Package("python", "Typing_Extensions", "4.16", "PSF-2.0")], _policy(exception)).ok
    assert not scan([Package("npm", "typing-extensions", "4.16", "PSF-2.0")], _policy(exception)).ok


def test_an_exception_covers_only_the_license_it_names() -> None:
    result = scan([Package("npm", "lru-cache", "12.0.0", "GPL-3.0-only")], _policy(BLUE_OAK))

    assert not result.ok
    assert "exception covers 'BlueOak-1.0.0' only" in result.violations[0].problem
    assert result.unused_exceptions == (BLUE_OAK,)


def test_an_unused_exception_fails() -> None:
    gone = scan([Package("npm", "vue", "3.5.43", "MIT")], _policy(BLUE_OAK))
    relicensed = scan([Package("npm", "lru-cache", "12.0.0", "MIT")], _policy(BLUE_OAK))

    assert not gone.ok
    assert gone.violations == ()
    assert gone.unused_exceptions == (BLUE_OAK,)
    assert relicensed.unused_exceptions == (BLUE_OAK,)


def test_a_package_installed_in_two_places_is_checked_once() -> None:
    result = scan(
        [
            Package("python", "pathspec", "1.0", "GPL-3.0-only", Path("/a")),
            Package("python", "pathspec", "1.0", "GPL-3.0-only", Path("/b")),
        ],
        _policy(),
    )

    assert result.checked == 1
    assert len(result.violations) == 1
