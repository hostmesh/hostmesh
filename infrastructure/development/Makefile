# SPDX-License-Identifier: Apache-2.0
#
# Local development stack. Run from the repository root with
# `make -C infrastructure/development <target>`.

.PHONY: up down reset smoke hosts trust

# The php service runs as the host user (see compose.yaml).
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

up: .env
		docker compose up --detach --wait

down: .env
		docker compose down

reset: .env
		docker compose down --volumes --remove-orphans

smoke:
		./smoke.sh

hosts:
		./hosts.sh --apply

trust:
		./trust.sh --apply

.env:
		cp .env.example .env
