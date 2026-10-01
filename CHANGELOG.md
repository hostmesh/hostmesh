# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- This changelog.
- Smoke test for the local development stack (`infrastructure/development/smoke.sh`). It checks PostgreSQL, Redis and the four hostnames over HTTPS and exits non-zero on failure.
- Local development stack in `infrastructure/development/`: PostgreSQL 18.6, Redis 8.10.2 and a Caddy 2.11.4 reverse proxy serving `app.`, `admin.`, `api.` and `agent.hostmesh.test` over HTTPS with certificates from Caddy's local CA. Each hostname serves a placeholder for now.
- `make up`, `make down` and `make reset` to start the stack, stop it, and stop it while wiping its volumes.
- `make hosts` and `make trust` (`hosts.sh`, `trust.sh`) to add the `hostmesh.test` names to `/etc/hosts` and to trust Caddy's local CA system-wide on Fedora/RHEL and Debian/Ubuntu.
- Setup and troubleshooting guide for the local development environment (`infrastructure/development/README.md`).
- Decision record 0003: development runtimes (PHP, Node) run in containers.
