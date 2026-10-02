# SPDX-License-Identifier: Apache-2.0
"""The test harness itself: async tests run, and HTTP never leaves the process."""

import httpx
import pytest
import respx

URL = "https://agent.hostmesh.test/ping"


async def test_async_tests_run_and_respx_answers_https(respx_mock: respx.MockRouter) -> None:
    route = respx_mock.get(URL).mock(return_value=httpx.Response(200, json={"ok": True}))

    async with httpx.AsyncClient() as client:
        response = await client.get(URL)

    assert route.called
    assert response.json() == {"ok": True}


async def test_an_unmocked_request_fails_instead_of_reaching_the_network(
    respx_mock: respx.MockRouter,
) -> None:
    async with httpx.AsyncClient() as client:
        with pytest.raises(AssertionError, match="not mocked"):
            await client.get("https://unmocked.invalid/")
