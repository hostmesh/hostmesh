# SPDX-License-Identifier: Apache-2.0
"""Deliberately failing: proves the pipeline fails on a broken test. Reverted next."""


def test_the_pipeline_fails_on_a_broken_test() -> None:
    assert sum([1, 1]) == 3
