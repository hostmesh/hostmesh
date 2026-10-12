# SPDX-License-Identifier: Apache-2.0
"""The three listings, read into Package records."""

import json
from pathlib import Path

import pytest

from hostmesh_hygiene.inventory import (
    InventoryError,
    Package,
    composer_packages,
    exported_names,
    npm_packages,
    python_packages,
)

ALIASES = {"MIT License": "MIT", "Apache Software License": "Apache-2.0"}


def _json(tmp_path: Path, name: str, data: object) -> Path:
    path = tmp_path / name
    path.write_text(json.dumps(data), encoding="utf-8")
    return path


def _dist(venv: Path, name: str, metadata: str) -> Path:
    directory = venv / "lib" / "python3.13" / "site-packages" / f"{name}-1.0.dist-info"
    directory.mkdir(parents=True)
    (directory / "METADATA").write_text(metadata, encoding="utf-8")
    return directory


def test_composer_packages_with_several_licenses_offer_a_choice(tmp_path: Path) -> None:
    listing = _json(
        tmp_path,
        "composer.json",
        {
            "name": "hostmesh/control-plane",
            "version": "1.0.0+no-version-set",
            "license": ["Apache-2.0"],
            "dependencies": {
                "nette/utils": {
                    "version": "v4.1.5",
                    "license": ["BSD-3-Clause", "GPL-2.0-only", "GPL-3.0-only"],
                },
                "brick/math": {"version": "1.0.0", "license": ["MIT"]},
                "acme/unlicensed": {"version": "2.0.0", "license": []},
            },
        },
    )
    vendor = tmp_path / "vendor"

    assert composer_packages(listing, vendor) == [
        Package("composer", "acme/unlicensed", "2.0.0", "", vendor / "acme/unlicensed"),
        Package("composer", "brick/math", "1.0.0", "MIT", vendor / "brick/math"),
        Package(
            "composer",
            "nette/utils",
            "v4.1.5",
            "BSD-3-Clause OR GPL-2.0-only OR GPL-3.0-only",
            vendor / "nette/utils",
        ),
    ]


def test_npm_packages_keep_the_part_of_the_path_below_the_workspace(tmp_path: Path) -> None:
    listing = _json(
        tmp_path,
        "pnpm.json",
        {
            "MIT": [
                {
                    "name": "vue",
                    "versions": ["3.5.43"],
                    "paths": ["/workspace/web/node_modules/.pnpm/vue@3.5.43/node_modules/vue"],
                    "license": "MIT",
                },
                {
                    "name": "@babel/parser",
                    "versions": ["7.29.0", "7.29.7"],
                    "paths": [
                        "/x/node_modules/.pnpm/@babel+parser@7.29.0/node_modules/@babel/parser",
                        "/x/node_modules/.pnpm/@babel+parser@7.29.7/node_modules/@babel/parser",
                    ],
                    "license": "MIT",
                },
            ],
            "(MIT OR CC0-1.0)": [
                {
                    "name": "type-fest",
                    "versions": ["4.41.0"],
                    "paths": [],
                    "license": "(MIT OR CC0-1.0)",
                }
            ],
        },
    )
    web = tmp_path / "web"

    assert npm_packages(listing, web) == [
        Package(
            "npm",
            "@babel/parser",
            "7.29.0, 7.29.7",
            "MIT",
            web / "node_modules/.pnpm/@babel+parser@7.29.7/node_modules/@babel/parser",
        ),
        Package("npm", "type-fest", "4.41.0", "(MIT OR CC0-1.0)", None),
        Package(
            "npm", "vue", "3.5.43", "MIT", web / "node_modules/.pnpm/vue@3.5.43/node_modules/vue"
        ),
    ]


def test_npm_unknown_license_is_reported_as_undeclared(tmp_path: Path) -> None:
    listing = _json(
        tmp_path,
        "pnpm.json",
        {"Unknown": [{"name": "mystery", "versions": ["1.0"], "paths": [], "license": "Unknown"}]},
    )

    assert npm_packages(listing, tmp_path) == [Package("npm", "mystery", "1.0", "", None)]


def test_python_metadata_is_read_in_order_of_reliability(tmp_path: Path) -> None:
    venv = tmp_path / ".venv"
    modern = _dist(
        venv,
        "httpx",
        "Metadata-Version: 2.4\nName: httpx\nVersion: 1.0\nLicense-Expression: BSD-3-Clause\n"
        "Classifier: License :: OSI Approved :: MIT License\n",
    )
    field = _dist(
        venv, "idna", "Metadata-Version: 2.1\nName: idna\nVersion: 1.0\nLicense: BSD-3-Clause\n"
    )
    classifier = _dist(
        venv,
        "pluggy",
        "Metadata-Version: 2.1\nName: pluggy\nVersion: 1.0\nLicense: MIT License\n"
        "Classifier: Programming Language :: Python\n"
        "Classifier: License :: OSI Approved :: MIT License\n",
    )
    two = _dist(
        venv,
        "Both_Ways",
        "Metadata-Version: 2.1\nName: Both_Ways\nVersion: 1.0\n"
        "Classifier: License :: OSI Approved :: MIT License\n"
        "Classifier: License :: OSI Approved :: Apache Software License\n",
    )
    prose = _dist(
        venv,
        "longtext",
        "Metadata-Version: 2.1\nName: longtext\nVersion: 1.0\n"
        "License: Copyright (c) someone\n        Permission is hereby granted\n",
    )
    unknown = _dist(
        venv,
        "odd",
        "Metadata-Version: 2.1\nName: odd\nVersion: 1.0\n"
        "Classifier: License :: Other/Proprietary License\n",
    )

    assert python_packages(venv, ALIASES) == [
        Package("python", "Both_Ways", "1.0", "MIT AND Apache-2.0", two),
        Package("python", "httpx", "1.0", "BSD-3-Clause", modern),
        Package("python", "idna", "1.0", "BSD-3-Clause", field),
        Package("python", "longtext", "1.0", "", prose),
        Package("python", "odd", "1.0", "Other/Proprietary License", unknown),
        Package("python", "pluggy", "1.0", "MIT", classifier),
    ]


def test_exported_names_are_normalised_and_skip_comments(tmp_path: Path) -> None:
    requirements = tmp_path / "python-prod.txt"
    requirements.write_text(
        "# This file was autogenerated by uv via the following command:\n"
        "#    uv export --locked --no-dev\n"
        "anyio==4.15.1\n"
        "    # via httpx\n"
        "Typing_Extensions==4.16.0 ; python_full_version < '3.15'\n"
        "\n",
        encoding="utf-8",
    )

    assert exported_names(requirements) == frozenset({"anyio", "typing-extensions"})


@pytest.mark.parametrize("content", ["{}", '{"dependencies": {}}', "not json", "[]"])
def test_an_empty_listing_is_an_error(tmp_path: Path, content: str) -> None:
    listing = tmp_path / "listing.json"
    listing.write_text(content, encoding="utf-8")

    with pytest.raises(InventoryError, match=r"listing\.json"):
        composer_packages(listing, tmp_path)
    with pytest.raises(InventoryError, match=r"listing\.json"):
        npm_packages(listing, tmp_path)


def test_a_missing_listing_is_an_error(tmp_path: Path) -> None:
    with pytest.raises(InventoryError, match=r"nope\.json"):
        composer_packages(tmp_path / "nope.json", tmp_path)
    with pytest.raises(InventoryError, match=r"nope\.txt"):
        exported_names(tmp_path / "nope.txt")


def test_a_virtual_environment_without_packages_is_an_error(tmp_path: Path) -> None:
    with pytest.raises(InventoryError, match=r"\.venv"):
        python_packages(tmp_path / ".venv", ALIASES)


def test_an_export_without_packages_is_an_error(tmp_path: Path) -> None:
    requirements = tmp_path / "python-prod.txt"
    requirements.write_text("# nothing\n", encoding="utf-8")

    with pytest.raises(InventoryError, match=r"python-prod\.txt"):
        exported_names(requirements)
