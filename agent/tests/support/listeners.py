# SPDX-License-Identifier: Apache-2.0
"""Finds listening internet sockets in the current process.

The agent must never accept inbound network traffic (spec section 5). This is
the instrument the no-listener tests use: it inspects the file descriptors of
the test process and reports every IPv4 or IPv6 socket that can receive
unsolicited traffic.
"""

import os
import socket
import stat
from dataclasses import dataclass
from pathlib import Path
from typing import Literal

_FD_DIRECTORY = Path("/proc/self/fd")
_INET_FAMILIES = frozenset({socket.AF_INET, socket.AF_INET6})


@dataclass(frozen=True, slots=True)
class Listener:
    """An internet socket that accepts unsolicited inbound traffic."""

    kind: Literal["stream", "datagram"]
    host: str
    port: int


def inet_listeners() -> list[Listener]:
    """Return every listening IPv4 or IPv6 socket the current process holds.

    A stream socket listens once it accepts connections. Any other socket
    listens when it is bound to a port and not connected to a peer. Unix
    sockets and connected outbound sockets are not listeners.
    """
    descriptors = sorted(int(path.name) for path in _FD_DIRECTORY.iterdir())
    found = (_inspect(descriptor) for descriptor in descriptors)
    return [listener for listener in found if listener is not None]


def _inspect(descriptor: int) -> Listener | None:
    try:
        if not stat.S_ISSOCK(os.fstat(descriptor).st_mode):
            return None
        duplicate = os.dup(descriptor)
    except OSError:
        # Closed since the directory was listed, such as the descriptor used to list it.
        return None

    with socket.socket(fileno=duplicate) as sock:
        if sock.family not in _INET_FAMILIES:
            return None
        host, port = sock.getsockname()[:2]
        kind: Literal["stream", "datagram"]
        if sock.type == socket.SOCK_STREAM:
            kind = "stream"
            listening = bool(sock.getsockopt(socket.SOL_SOCKET, socket.SO_ACCEPTCONN))
        else:
            kind = "datagram"
            listening = port != 0 and not _has_peer(sock)
        return Listener(kind, host, port) if listening else None


def _has_peer(sock: socket.socket) -> bool:
    try:
        sock.getpeername()
    except OSError:
        return False
    return True
