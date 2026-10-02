# SPDX-License-Identifier: Apache-2.0
"""The agent is a pure outbound client: it never listens (spec section 5)."""

import asyncio
import socket
from collections.abc import Callable, Coroutine
from typing import Any

import pytest

from agent.runner import run
from tests.support.listeners import Listener, inet_listeners

RunLoop = Callable[[asyncio.Event], Coroutine[Any, Any, None]]


async def listeners_while_running(run_loop: RunLoop) -> list[Listener]:
    """Start a run loop, let it reach its first wait, and report the listeners it holds."""
    stop = asyncio.Event()
    task = asyncio.create_task(run_loop(stop))
    try:
        await asyncio.sleep(0)
        assert not task.done(), "the run loop exited on its own"
        return inet_listeners()
    finally:
        stop.set()
        await task


async def test_starting_the_agent_opens_no_listening_socket() -> None:
    assert await listeners_while_running(run) == []


@pytest.mark.parametrize(
    ("socket_type", "kind"),
    [
        pytest.param(socket.SOCK_STREAM, "stream", id="tcp"),
        pytest.param(socket.SOCK_DGRAM, "datagram", id="udp"),
    ],
)
async def test_a_run_loop_that_listens_is_caught(socket_type: socket.SocketKind, kind: str) -> None:
    async def listening_loop(stop: asyncio.Event) -> None:
        with socket.socket(socket.AF_INET, socket_type) as sock:
            sock.bind(("127.0.0.1", 0))
            if socket_type == socket.SOCK_STREAM:
                sock.listen()
            await stop.wait()

    listeners = await listeners_while_running(listening_loop)

    assert [listener.kind for listener in listeners] == [kind]
