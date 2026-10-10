# SPDX-License-Identifier: Apache-2.0
"""Fixtures for tests that need the Debian 13 test nodes or the ACME and DNS doubles."""

import os
from collections.abc import Iterator
from pathlib import Path

import httpx
import pytest
from hostmesh_testing import NODE_NAMES, Config, IncusCli, Node, NodeHarness

from tests.support.doubles import CHALLENGE_API, fetch_pebble_ca


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


@pytest.fixture(scope="session")
def pebble_ca(tmp_path_factory: pytest.TempPathFactory) -> Path:
    """The CA certificate that signs Pebble's HTTPS API."""
    return fetch_pebble_ca(tmp_path_factory.mktemp("pebble"))


@pytest.fixture
def challenge_api() -> Iterator[httpx.Client]:
    """The management API of the challenge test server."""
    with httpx.Client(base_url=CHALLENGE_API, timeout=10.0) as client:
        yield client
