# SPDX-License-Identifier: Apache-2.0
"""The rendered THIRD_PARTY_NOTICES."""

from pathlib import Path

from hostmesh_hygiene.inventory import Package
from hostmesh_hygiene.notices import render
from hostmesh_hygiene.policy import Vendored


def _package(root: Path, ecosystem: str, name: str, files: dict[str, str]) -> Package:
    directory = root / ecosystem / name
    directory.mkdir(parents=True)
    for file_name, text in files.items():
        target = directory / file_name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(text.encode("utf-8"))
    return Package(ecosystem, name, "1.2.3", "MIT", directory)


def test_each_package_is_listed_with_its_license_and_license_text(tmp_path: Path) -> None:
    vue = _package(tmp_path, "npm", "vue", {"LICENSE": "MIT License\n\nCopyright (c) Yuxi You\n"})

    text = render([vue], [], tmp_path)

    assert "vue\nLicense: MIT\nhttps://www.npmjs.com/package/vue\n" in text
    assert "Copyright (c) Yuxi You" in text


def test_no_version_number_is_printed(tmp_path: Path) -> None:
    vue = _package(tmp_path, "npm", "vue", {"LICENSE": "MIT License\n"})

    assert "1.2.3" not in render([vue], [], tmp_path)


def test_sections_follow_the_ecosystems_and_packages_are_sorted(tmp_path: Path) -> None:
    packages = [
        _package(tmp_path, "python", "httpx", {"licenses/LICENSE.md": "BSD\n"}),
        _package(tmp_path, "npm", "vue", {"LICENSE": "MIT\n"}),
        _package(tmp_path, "npm", "Pinia", {"LICENSE": "MIT\n"}),
        _package(tmp_path, "composer", "laravel/framework", {"LICENSE.md": "MIT\n"}),
    ]

    text = render(packages, [], tmp_path)

    positions = [
        text.index(marker)
        for marker in (
            "Composer packages",
            "\nlaravel/framework\n",
            "npm packages",
            "\nPinia\n",
            "\nvue\n",
            "Python packages",
            "\nhttpx\n",
        )
    ]
    assert positions == sorted(positions)
    assert "https://packagist.org/packages/laravel/framework" in text
    assert "https://pypi.org/project/httpx/" in text


def test_every_license_and_notice_file_of_a_package_is_included(tmp_path: Path) -> None:
    package = _package(
        tmp_path,
        "python",
        "cryptography",
        {
            "licenses/LICENSE": "choose one\n",
            "licenses/LICENSE.APACHE": "Apache text\n",
            "licenses/LICENSE.BSD": "BSD text\n",
            "NOTICE": "Attribution notice\n",
            "COPYING.txt": "copying\n",
            "LICENCE": "british spelling\n",
            "README.md": "not a license\n",
            "METADATA": "Name: cryptography\n",
        },
    )

    text = render([package], [], tmp_path)

    for expected in (
        "choose one",
        "Apache text",
        "BSD text",
        "Attribution notice",
        "copying",
        "british spelling",
    ):
        assert expected in text
    assert "not a license" not in text
    assert "Name: cryptography" not in text


def test_a_package_without_a_license_file_says_so(tmp_path: Path) -> None:
    bare = _package(tmp_path, "npm", "bare", {"index.js": "x\n"})
    nowhere = Package("npm", "nowhere", "1.0.0", "ISC", None)

    text = render([bare, nowhere], [], tmp_path)

    assert text.count("ships no license file; it declares the license named above") == 2


def test_vendored_source_comes_first_with_its_paths_and_license_file(tmp_path: Path) -> None:
    (tmp_path / "ui").mkdir()
    (tmp_path / "ui/LICENSE").write_text("MIT License\n\nCopyright (c) 2023 unovue\n", "utf-8")
    vendored = [
        Vendored(
            "shadcn-vue", "MIT", "https://github.com/unovue/shadcn-vue", ("ui/",), "ui/LICENSE"
        ),
        Vendored(
            "laravel/laravel", "MIT", "https://github.com/laravel/laravel", ("artisan",), None
        ),
    ]
    vue = _package(tmp_path, "npm", "vue", {"LICENSE": "MIT\n"})

    text = render([vue], vendored, tmp_path)

    assert text.index("Source code in this repository") < text.index("npm packages")
    assert "shadcn-vue\nLicense: MIT\nhttps://github.com/unovue/shadcn-vue\nFiles:\n  ui/\n" in text
    assert "Copyright (c) 2023 unovue" in text
    assert (
        "laravel/laravel\nLicense: MIT\nhttps://github.com/laravel/laravel\nFiles:\n  artisan\n"
        in text
    )


def test_the_output_does_not_depend_on_where_node_modules_lives(tmp_path: Path) -> None:
    first = _package(tmp_path / "a", "npm", "vue", {"LICENSE": "MIT\n"})
    second = _package(tmp_path / "b", "npm", "vue", {"LICENSE": "MIT\n"})

    assert render([first], [], tmp_path / "a") == render([second], [], tmp_path / "b")
    assert str(tmp_path) not in render([first], [], tmp_path / "a")


def test_line_endings_and_trailing_spaces_are_normalised(tmp_path: Path) -> None:
    unix = _package(tmp_path / "a", "npm", "vue", {"LICENSE": "MIT License\n\nText\n"})
    windows = _package(
        tmp_path / "b", "npm", "vue", {"LICENSE": "﻿MIT License  \r\n\r\nText\t\r\n\r\n\r\n"}
    )

    assert render([unix], [], tmp_path) == render([windows], [], tmp_path)


def test_bytes_that_are_not_utf8_do_not_crash(tmp_path: Path) -> None:
    package = _package(tmp_path, "npm", "latin", {"LICENSE": "ok\n"})
    assert package.path is not None
    (package.path / "LICENSE").write_bytes(b"Copyright \xa9 someone\n")

    assert "Copyright" in render([package], [], tmp_path)


def test_the_file_ends_with_exactly_one_newline_and_says_it_is_generated(tmp_path: Path) -> None:
    text = render([_package(tmp_path, "npm", "vue", {"LICENSE": "MIT\n"})], [], tmp_path)

    assert text.endswith("\n")
    assert not text.endswith("\n\n")
    assert "make -C infrastructure/hygiene notices" in text
