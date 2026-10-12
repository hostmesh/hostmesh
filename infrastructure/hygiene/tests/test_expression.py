# SPDX-License-Identifier: Apache-2.0
"""SPDX license expressions against a set of allowed identifiers."""

import pytest

from hostmesh_hygiene.expression import InvalidExpression, satisfied

ALLOWED = frozenset({"MIT", "BSD-3-Clause", "Apache-2.0"})


@pytest.mark.parametrize(
    "expression",
    [
        "MIT",
        "  MIT  ",
        "(MIT)",
        "MIT OR GPL-3.0-only",
        "GPL-2.0-only OR BSD-3-Clause OR GPL-3.0-only",
        "MIT AND Apache-2.0",
        "(MIT OR CC0-1.0)",
        "GPL-3.0-only OR (MIT AND BSD-3-Clause)",
    ],
)
def test_an_expression_that_can_be_met_with_allowed_licenses_passes(expression: str) -> None:
    assert satisfied(expression, ALLOWED)


@pytest.mark.parametrize(
    "expression",
    [
        "GPL-3.0-only",
        "MIT AND GPL-3.0-only",
        "GPL-2.0-only OR GPL-3.0-only",
        "MIT AND (GPL-3.0-only OR LGPL-3.0-only)",
        "GPL-2.0-or-later",
        "GPL-2.0+",
        "mit",
    ],
)
def test_an_expression_that_needs_a_license_off_the_list_fails(expression: str) -> None:
    assert not satisfied(expression, ALLOWED)


def test_and_binds_tighter_than_or() -> None:
    # MIT OR (GPL AND GPL), not (MIT OR GPL) AND GPL.
    assert satisfied("MIT OR GPL-2.0-only AND GPL-3.0-only", ALLOWED)
    assert not satisfied("GPL-2.0-only AND GPL-3.0-only OR GPL-3.0-only", ALLOWED)


def test_an_exception_clause_passes_only_when_the_whole_clause_is_allowed() -> None:
    clause = "Apache-2.0 WITH LLVM-exception"

    assert not satisfied(clause, ALLOWED)
    assert satisfied(clause, ALLOWED | {clause})


@pytest.mark.parametrize(
    "expression",
    [
        "",
        "   ",
        "MIT License",
        "MIT OR",
        "OR MIT",
        "(MIT",
        "MIT)",
        "MIT AND AND MIT",
        "MIT WITH",
        "see LICENSE.txt",
        "MIT, Apache-2.0",
        "GPL-3.0-only OR MIT License",
    ],
)
def test_text_that_is_not_an_expression_is_invalid(expression: str) -> None:
    with pytest.raises(InvalidExpression):
        satisfied(expression, ALLOWED)
