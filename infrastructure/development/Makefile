# SPDX-License-Identifier: Apache-2.0
#
# Local development stack. Run from the repository root with
# `make -C infrastructure/development <target>`.

.PHONY: up down reset smoke

up: .env
		docker compose up --detach --wait

down: .env
		docker compose down

reset: .env
		docker compose down --volumes --remove-orphans

smoke:
		./smoke.sh

.env:
		cp .env.example .env
