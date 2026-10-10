# SPDX-License-Identifier: Apache-2.0
#
# Test-node harness. Run from the repository root with
# `make -C infrastructure/testing <target>`.

.PHONY: install lint lint-fix typecheck test check up reset destroy status

# --locked fails when uv.lock is out of date instead of rewriting it.
UV_RUN := uv run --locked

install:
		uv sync --locked

lint:
		$(UV_RUN) ruff check .
		$(UV_RUN) ruff format --check .

lint-fix:
		$(UV_RUN) ruff format .
		$(UV_RUN) ruff check --fix .

typecheck:
		$(UV_RUN) mypy

test:
		$(UV_RUN) pytest

check: lint typecheck test

# The nodes themselves. These need Incus (see README.md).
up reset destroy status:
		$(UV_RUN) python -m hostmesh_testing $@
