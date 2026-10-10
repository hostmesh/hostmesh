# SPDX-License-Identifier: Apache-2.0
"""Where the ACME and DNS test doubles of the development stack listen.

Pebble is Let's Encrypt's ACME test server. Its challenge test server is a
mock DNS server with an HTTP management API. Both are services in
infrastructure/development/compose.yaml, published on 127.0.0.1.
"""

import shutil
import subprocess
from pathlib import Path

PEBBLE_DIRECTORY = "https://localhost:14000/dir"
PEBBLE_MANAGEMENT = "https://localhost:15000"
CHALLENGE_API = "http://localhost:8055"
DNS_HOST = "127.0.0.1"
DNS_PORT = 8053

_COMPOSE_FILE = Path(__file__).resolve().parents[3] / "infrastructure/development/compose.yaml"
_CA_IN_CONTAINER = "pebble:/test/certs/pebble.minica.pem"


def fetch_pebble_ca(directory: Path) -> Path:
    """Copies the CA of Pebble's HTTPS API out of its container.

    The file is never committed and never goes into a trust store: its
    private key is public.
    """
    docker = shutil.which("docker")
    if docker is None:
        raise RuntimeError("docker is not installed: the test doubles run in the development stack")
    target = directory / "pebble.minica.pem"
    copied = subprocess.run(  # noqa: S603 - an argument list, never a shell
        [docker, "compose", "--file", str(_COMPOSE_FILE), "cp", _CA_IN_CONTAINER, str(target)],
        stdin=subprocess.DEVNULL,
        capture_output=True,
        text=True,
        check=False,
    )
    if copied.returncode != 0:
        raise RuntimeError(
            "Pebble is not running. Start it with `make -C infrastructure/development doubles` "
            f"(or `up`). docker said: {copied.stderr.strip()}"
        )
    return target
