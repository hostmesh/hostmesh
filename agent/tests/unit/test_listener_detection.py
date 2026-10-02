# SPDX-License-Identifier: Apache-2.0
"""The listener detector must see what it claims to see.

These tests open real sockets on the loopback interface, inside the test
process only. Nothing here touches the system outside the process.
"""

import socket
from pathlib import Path

import pytest

from tests.support.listeners import Listener, inet_listeners

LOOPBACK = "127.0.0.1"
LOOPBACK_V6 = "::1"


def test_a_clean_process_has_no_listeners() -> None:
    assert inet_listeners() == []


def test_flags_a_listening_tcp_socket() -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind((LOOPBACK, 0))
        sock.listen()
        port = sock.getsockname()[1]

        assert inet_listeners() == [Listener("stream", LOOPBACK, port)]


def test_flags_a_bound_udp_socket() -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.bind((LOOPBACK, 0))
        port = sock.getsockname()[1]

        assert inet_listeners() == [Listener("datagram", LOOPBACK, port)]


def test_flags_a_listener_on_ipv6() -> None:
    with socket.socket(socket.AF_INET6, socket.SOCK_STREAM) as sock:
        try:
            sock.bind((LOOPBACK_V6, 0))
        except OSError:
            pytest.skip("no IPv6 loopback on this machine")
        sock.listen()
        port = sock.getsockname()[1]

        assert inet_listeners() == [Listener("stream", LOOPBACK_V6, port)]


def test_ignores_a_tcp_socket_that_is_bound_but_not_listening() -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind((LOOPBACK, 0))

        assert inet_listeners() == []


def test_ignores_connected_tcp_sockets() -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as server:
        server.bind((LOOPBACK, 0))
        server.listen()
        client = socket.create_connection(server.getsockname())
        accepted, _ = server.accept()

    # The listening socket is closed. Both ends of the connection remain.
    with client, accepted:
        assert inet_listeners() == []


def test_ignores_a_connected_udp_socket() -> None:
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
        sock.connect((LOOPBACK, 9))

        assert inet_listeners() == []


def test_ignores_a_listening_unix_socket(tmp_path: Path) -> None:
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as sock:
        sock.bind(str(tmp_path / "agent.sock"))
        sock.listen()

        assert inet_listeners() == []
