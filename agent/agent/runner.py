# SPDX-License-Identifier: Apache-2.0
"""The agent's run loop.

A stub for now: it idles until asked to stop. The reconcile loop (long-poll,
reconcile, report) replaces the body. Whatever it becomes, it must never open
a listening socket: the agent is a pure outbound client (spec section 5).
"""

import asyncio


async def run(stop: asyncio.Event) -> None:
    """Run the agent until ``stop`` is set."""
    await stop.wait()
