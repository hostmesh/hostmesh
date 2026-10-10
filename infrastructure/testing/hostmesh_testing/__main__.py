# SPDX-License-Identifier: Apache-2.0
"""Command line of the test-node harness: `python -m hostmesh_testing <command>`."""

import argparse
import os
import sys
from collections.abc import Sequence

from hostmesh_testing.incus import HarnessError, IncusCli, Runner
from hostmesh_testing.nodes import NODE_NAMES, Config, NodeHarness

_COMMANDS = {
    "up": "create the nodes that are missing and reset the others to their clean snapshot",
    "create": "create the nodes that are missing",
    "reset": "restore both nodes to their clean snapshot",
    "destroy": "delete both nodes",
    "status": "list the nodes",
}


def main(argv: Sequence[str] | None = None, *, runner: Runner | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="hostmesh_testing",
        description="Two disposable Debian 13 test nodes on Incus. "
        "HOSTMESH_NODE_TYPE is container (default) or vm.",
    )
    commands = parser.add_subparsers(dest="command", required=True)
    for name, description in _COMMANDS.items():
        commands.add_parser(name, help=description)
    execute = commands.add_parser("exec", help="run a command on a node: exec <node> -- <argv>")
    execute.add_argument("node", choices=NODE_NAMES)
    execute.add_argument("argv", nargs=argparse.REMAINDER)
    arguments = parser.parse_args(argv)

    try:
        incus = runner or IncusCli()
        harness = NodeHarness(incus, Config.from_environment(os.environ), report=print)
        match arguments.command:
            case "up":
                harness.up()
            case "create":
                harness.create()
            case "reset":
                harness.reset()
            case "destroy":
                harness.destroy()
            case "status":
                listed = incus.run(["list", f"^({'|'.join(NODE_NAMES)})$", "-c", "nstS4"])
                print(listed.stdout, end="")
                return listed.returncode
            case "exec":
                # argparse keeps the leading `--` on some Python versions and drops it on others.
                command = arguments.argv[1:] if arguments.argv[:1] == ["--"] else arguments.argv
                result = harness.node(arguments.node).run(command, check=False)
                print(result.stdout, end="")
                print(result.stderr, end="", file=sys.stderr)
                return result.returncode
    except HarnessError as error:
        print(f"hostmesh_testing: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
