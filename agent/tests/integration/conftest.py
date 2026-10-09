# SPDX-License-Identifier: Apache-2.0
"""Fixtures for tests that need the Debian 13 test nodes."""

import os

import pytest
from hostmesh_testing import NODE_NAMES, Config, IncusCli, Node, NodeHarness


@pytest.fixture(scope="session")
def harness() -> NodeHarness:
    """Both nodes, created or reset to their clean snapshot once per run.

    They are left running afterwards, so the next run only resets them.
    """
    nodes = NodeHarness(IncusCli(), Config.from_environment(os.environ))
    nodes.up()
    return nodes


@pytest.fixture(scope="session")
def nodes(harness: NodeHarness) -> tuple[Node, ...]:
    return harness.nodes


@pytest.fixture(params=NODE_NAMES)
def node(request: pytest.FixtureRequest, harness: NodeHarness) -> Node:
    """Runs the test once on each node."""
    return harness.node(request.param)
