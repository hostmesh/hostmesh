# Local development environment

One command starts the services Hostmesh needs for local development, on the hostname layout used in production (spec section 28).

| Hostname              | Production role     | Serves locally today |
|-----------------------|---------------------|----------------------|
| `app.hostmesh.test`   | Customer portal     | Placeholder          |
| `admin.hostmesh.test` | Admin app           | Placeholder          |
| `api.hostmesh.test`   | Laravel API         | The control plane    |
| `agent.hostmesh.test` | Agent mTLS endpoint | Placeholder          |

The stack is a Docker Compose project with PostgreSQL 18, Redis 8, Caddy 2, a PHP 8.5 service for the control plane and a Node 24 service for the web workspace. Caddy terminates HTTPS with certificates from its own local certificate authority (CA) and proxies `api.hostmesh.test` to PHP-FPM. A Node 24 service runs the commands of the web workspace (see decisions [0003](../../docs/decisions/0003-development-runtimes-in-containers.md) and [0007](../../docs/decisions/0007-node-24-pnpm-and-typescript-6-for-the-web-workspace.md)). The two apps are not served on `app.hostmesh.test` and `admin.hostmesh.test` yet.

## Requirements

- Linux
- Docker Engine with the Compose plugin (`docker compose version` works), and permission to run `docker`
- GNU Make, Bash and curl
- `sudo`, for the two one-time setup steps
- Ports 80 and 443 free on `127.0.0.1`

Developed with Docker 29.8.2 and Docker Compose 5.5.1 on Fedora 44. CI runs it on Ubuntu.

## First-time setup

From the repository root:

```bash
make -C infrastructure/development up        # build and start the stack
make -C infrastructure/development install   # install the control plane and migrate its database
make -C infrastructure/development hosts     # add the hostnames to /etc/hosts (sudo)
make -C infrastructure/development trust     # trust the local CA system-wide (sudo)
make -C infrastructure/development smoke     # check everything
```

The smoke test must end with `All checks passed`. Then open <https://app.hostmesh.test/> and <https://api.hostmesh.test/api/v1/health>.

## Commands

Run them as `make -C infrastructure/development <target>`, or as `make <target>` from this directory.

| Target    | What it does                                                                                                                                                                                                          |
|-----------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `up`      | Creates `.env` from `.env.example` if it is missing, starts the stack and waits until every service is healthy.                                                                                                       |
| `down`    | Stops the stack. The database, Redis data and the local CA are kept.                                                                                                                                                  |
| `reset`   | Stops the stack and deletes all its volumes: the database, Redis data, the pnpm store and the local CA. After the next `up`, run `trust` again.                                                                       |
| `install` | Installs the control plane's Composer dependencies, creates its `.env` and application key if they are missing, runs its migrations, and installs the web workspace's dependencies from its lockfile. Safe to repeat. |
| `smoke`   | Checks PostgreSQL, Redis, the placeholder hostnames over HTTPS, the API's health endpoint and the `node` service's Node and pnpm versions. Exits non-zero if anything fails.                                          |
| `hosts`   | Adds the four hostnames to `/etc/hosts`. Does nothing if they are already there.                                                                                                                                      |
| `trust`   | Installs the local CA's root certificate in the system trust store.                                                                                                                                                   |

## What `hosts` and `trust` change on your machine

`hosts` appends this block to `/etc/hosts`. Run `./hosts.sh` to print it without changing anything.

```text
# BEGIN hostmesh development
127.0.0.1 app.hostmesh.test admin.hostmesh.test api.hostmesh.test agent.hostmesh.test
# END hostmesh development
```

`trust` copies the CA root certificate out of the Caddy container and installs it as:

- `/etc/pki/ca-trust/source/anchors/hostmesh-development-ca.crt` on Fedora and RHEL, then runs `update-ca-trust`
- `/usr/local/share/ca-certificates/hostmesh-development-ca.crt` on Debian and Ubuntu, then runs `update-ca-certificates`

Run `./trust.sh` to print the certificate without installing it.

The CA's private key stays in the `caddy_data` Docker volume. Anyone who can read that volume can issue certificates that this machine trusts, so use this setup on a development machine only.

### Browsers

Browsers can keep their own certificate stores. If a browser still warns after `make trust`, import the installed certificate file (the path above) and restart the browser.

- **Chromium-based browsers:** install `certutil` (package `nss-tools` on Fedora, `libnss3-tools` on Debian and Ubuntu), then:

  ```bash
  certutil -d sql:$HOME/.local/share/pki/nssdb -A -t "C,," -n "Hostmesh development CA" \
    -i /etc/pki/ca-trust/source/anchors/hostmesh-development-ca.crt
  ```

  Chromium before version 146 uses `sql:$HOME/.pki/nssdb`. Use whichever directory exists.
- **Firefox:** Settings > Privacy & Security > Certificates > View Certificates > Authorities > Import, and trust it to identify websites.

After a `reset`, remove the old entry first (`certutil -d <database> -D -n "Hostmesh development CA"`, or delete it in the Firefox dialog) and import the new one.

## Control plane

PHP runs in the `php` service as your user, with `control-plane/` mounted at `/var/www/html` and `contracts/` at `/var/www/contracts`. Run commands from this directory:

```bash
docker compose exec php composer check                  # Pint, Larastan, Pest with the coverage gate
docker compose exec php composer lint:fix               # fix code style
docker compose exec php composer openapi                # regenerate contracts/api/openapi.json
docker compose exec php vendor/bin/pest --filter=health
docker compose exec php php artisan route:list
```

Tests use the `hostmesh_test` database and Redis database 15, never the development data. See [`control-plane/README.md`](../../control-plane/README.md).

## Web workspace

Node runs in the `node` service as your user, with `web/` mounted at `/workspace/web` and `contracts/` mounted read-only at `/workspace/contracts`. pnpm keeps its store in the `pnpm_home` volume. The service runs commands only; it serves nothing.

The image pins Node and pnpm to the versions in `web/.nvmrc` and the `packageManager` field of `web/package.json`. The smoke test fails when they differ; change all three together and run `make up` to rebuild.

```bash
docker compose exec node pnpm check                      # ESLint, vue-tsc, Vitest and both builds
docker compose exec node pnpm lint:fix                   # fix what ESLint can fix
docker compose exec node pnpm install                    # after changing a dependency
docker compose exec node pnpm vitest run --project admin
```

See [`web/README.md`](../../web/README.md).

## PostgreSQL and Redis

Neither is published to the host. Containers in this Compose project reach them as `postgres:5432` and `redis:6379`. The database name, user and password are in `.env`.

From this directory:

```bash
docker compose exec postgres psql -U hostmesh -d hostmesh
docker compose exec redis redis-cli
```

## Troubleshooting

**`make up` fails with "address already in use" or "port is already allocated".** Something else holds port 80 or 443 on `127.0.0.1`. Find it with `sudo ss -ltnp | grep -E ':(80|443)\s'` and stop it.

**`make up` waits and then reports a service as unhealthy.** Run `docker compose ps` and `docker compose logs <service>` from this directory.

**Smoke test: "Is the stack up?" or "Connection refused".** Run `make up`.

**Smoke test: "Name does not resolve".** Run `make hosts`, then check with `getent hosts app.hostmesh.test`.

**Smoke test: "Certificate is not trusted".** Run `make trust`. This is expected after every `make reset`, because a reset creates a new CA.

**The smoke test passes but the browser shows a certificate warning.** See [Browsers](#browsers).

**PostgreSQL rejects the password after you edited `.env`.** PostgreSQL applies `.env` only when its volume is first created. Run `make reset` and `make up`. This deletes the database.

**Caddy cannot read its `Caddyfile` ("permission denied") on a host with SELinux enforcing.** Not tested: the machine this was developed on has SELinux disabled. The usual fix is to change the mount in `compose.yaml` to `./Caddyfile:/etc/caddy/Caddyfile:ro,z`.

**Tests fail with `database "hostmesh_test" does not exist`.** The database is created with the PostgreSQL volume. A volume from before the control plane existed does not have it. Create it without losing data: `docker compose exec postgres createdb -U hostmesh hostmesh_test`.

**Files in `control-plane/` are owned by root.** The `php` service was started without the Makefile, which sets your user and group id. Run `make down`, fix the ownership with `sudo chown -R "$(id -u):$(id -g)" ../../control-plane`, and start with `make up`.

## Undo

```bash
make -C infrastructure/development reset
sudo sed -i '/^# BEGIN hostmesh development$/,/^# END hostmesh development$/d' /etc/hosts
# Fedora, RHEL:
sudo rm /etc/pki/ca-trust/source/anchors/hostmesh-development-ca.crt && sudo update-ca-trust
# Debian, Ubuntu:
sudo rm /usr/local/share/ca-certificates/hostmesh-development-ca.crt && sudo update-ca-certificates --fresh
```
