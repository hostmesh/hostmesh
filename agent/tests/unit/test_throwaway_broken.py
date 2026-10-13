# SPDX-License-Identifier: Apache-2.0
"""Throwaway (issue #105): proves that a failing suite turns its gate red."""


def test_fails_on_purpose() -> None:
    assert 1 + 1 == 3
