# SPDX-License-Identifier: Apache-2.0
"""The ``hostmesh-agent`` command line."""

import argparse
from collections.abc import Sequence
from importlib.metadata import version

_DISTRIBUTION = "hostmesh-agent"


def main(argv: Sequence[str] | None = None) -> int:
    """Run the command line and return its exit status."""
    parser = argparse.ArgumentParser(prog="hostmesh-agent", description="Hostmesh node agent.")
    commands = parser.add_subparsers(dest="command", metavar="command", required=True)
    commands.add_parser("version", help="print the agent version and exit")
    parser.parse_args(argv)

    # `version` is the only command, so a successful parse means it was asked for.
    print(f"{_DISTRIBUTION} {version(_DISTRIBUTION)}")
    return 0
