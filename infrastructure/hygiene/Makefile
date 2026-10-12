# SPDX-License-Identifier: Apache-2.0
#
# License hygiene. Run from the repository root with
# `make -C infrastructure/hygiene <target>`.

.PHONY: install lint lint-fix typecheck test check

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
