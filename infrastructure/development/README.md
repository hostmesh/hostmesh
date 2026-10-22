# Local development environment

One command starts the services Hostmesh needs for local development, on the hostname layout used in production (spec section 28).

| Hostname              | Production role     | Serves locally today            |
|-----------------------|---------------------|---------------------------------|
| `app.hostmesh.test`   | Customer portal     | The portal (Vite dev server)    |
| `admin.hostmesh.test` | Admin app           | The admin app (Vite dev server) |
| `api.hostmesh.test`   | Laravel API         | The control plane               |
| `agent.hostmesh.test` | Agent mTLS endpoint | Placeholder                     |

The stack is a Docker Compose project with PostgreSQL 18, Redis 8, Caddy 2, a PHP 8.5 service for the control plane, a queue worker and a scheduler on the same image, and a Node 24 service for the web workspace.
Caddy terminates HTTPS with certificates from its own local certificate authority (CA) and proxies `api.hostmesh.test` to PHP-FPM.
A Node 24 service runs the commands of the web workspace (see decisions [0003](../../docs/decisions/0003-development-runtimes-in-containers.md) and [0007](../../docs/decisions/0007-node-24-pnpm-and-typescript-6-for-the-web-workspace.md)).
Two test doubles run beside them: Pebble, an ACME test server, and its challenge test server, a mock DNS server (see "Test doubles").
The `node` service serves both apps with Vite's dev server, with hot reload: Caddy proxies `admin.hostmesh.test` to port 5173 and `app.hostmesh.test` to port 5174. It starts them once `make install` has installed the dependencies. After a change to `web/pnpm-lock.yaml` or a Vite configuration run `docker compose restart node`; its output is in `docker compose logs node`. The dev servers do not send the Content Security Policy of the production setup (`infrastructure/nginx`).

## Requirements

- Linux
- Docker Engine with the Compose plugin (`docker compose version` works), and permission to run `docker`
- GNU Make, Bash and curl
- `sudo`, for the two one-time setup steps
- Ports 80 and 443 free on `127.0.0.1`, and 8053, 8055, 14000 and 15000 for the test doubles

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

The smoke test must end with `All checks passed`. Then open <https://admin.hostmesh.test/>, <https://app.hostmesh.test/> and <https://api.hostmesh.test/api/v1/health>.

## Commands

Run them as `make -C infrastructure/development <target>`, or as `make <target>` from this directory.

| Target    | What it does                                                                                                                                                                                                                                                                                                                                                                     |
|-----------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `up`      | Creates `.env` from `.env.example` if it is missing, builds the images whose Dockerfile changed, starts the stack and waits until every service is healthy and reloads Caddy's configuration, so that a changed `Caddyfile` takes effect.                                                                                                                                        |
| `doubles` | Starts only `pebble` and `challtestsrv`, the ACME and DNS test doubles. Enough for `make -C agent test-doubles`.                                                                                                                                                                                                                                                                 |
| `e2e-up`  | Builds both apps and switches the stack to end-to-end mode. See [End-to-end mode](#end-to-end-mode). `up` switches back.                                                                                                                                                                                                                                                         |
| `e2e`     | Seeds the end-to-end database and runs the Playwright suite. Needs `e2e-up` first. `ARGS="…"` is passed to Playwright.                                                                                                                                                                                                                                                           |
| `down`    | Stops the stack. The database, Redis data and the local CA are kept.                                                                                                                                                                                                                                                                                                             |
| `reset`   | Stops the stack and deletes all its volumes: the database, Redis data and the local CA. After the next `up`, run `trust` again.                                                                                                                                                                                                                                                  |
| `install` | Installs the control plane's Composer dependencies, creates its `.env` and application key if they are missing, runs its migrations, and installs the web workspace's dependencies from its lockfile and restarts the `node` service so that it serves both apps. Safe to repeat.                                                                                                |
| `smoke`   | Checks PostgreSQL, Redis, the app and agent hostnames over HTTPS, the API's health endpoint, that the queue worker runs a job dispatched to the application's queue, that the scheduler runs the audit retention tasks, the `node` service's Node and pnpm versions, its pnpm store and its Chromium build, and the ACME and DNS test doubles. Exits non-zero if anything fails. |
| `hosts`   | Adds the four hostnames to `/etc/hosts`. Does nothing if they are already there.                                                                                                                                                                                                                                                                                                 |
| `trust`   | Installs the local CA's root certificate in the system trust store.                                                                                                                                                                                                                                                                                                              |

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

PHP runs in the `php` service as your user, with `control-plane/` mounted at `/var/www/html`, `contracts/` at `/var/www/contracts` and `infrastructure/nginx/` read-only at `/var/www/infrastructure/nginx`. Run commands from this directory:

```bash
docker compose exec php composer check                  # Pint, Larastan, Pest with the coverage gate
docker compose exec php composer lint:fix               # fix code style
docker compose exec php composer openapi                # regenerate contracts/api/openapi.json
docker compose exec php vendor/bin/pest --filter=health
docker compose exec php php artisan route:list
```

Tests use the `hostmesh_test` database and Redis database 15, never the development data. See [`control-plane/README.md`](../../control-plane/README.md).

The control plane reads its hostnames from one setting, `HOSTMESH_DOMAIN=hostmesh.test`, in `control-plane/.env`. `make install` copies it from `.env.example` for a new checkout. An `.env` from before this setting existed needs the line added by hand; `APP_URL` is no longer read. For a production layout see [`infrastructure/nginx/`](../nginx/README.md).

### Queue worker

The `worker` service runs `php artisan queue:listen redis`. It starts a fresh PHP process for every job, so it picks up code changes without a restart. On a fresh checkout it waits until `make install` has created `vendor/` and `.env`.

```bash
docker compose logs --follow worker
```
A job that was dispatched but never ran shows as `pending` in the table `job_history`, until the scheduled sweep gives it a new job after 15 minutes. The test suite does not use this worker: tests run jobs in their own process, on Redis database 15.

### Scheduler

The `scheduler` service runs `php artisan schedule:work`, which starts `schedule:run` in a fresh PHP process every minute, so it picks up code changes without a restart. The tasks are defined in `withSchedule` in `control-plane/bootstrap/app.php`. A task only dispatches a job; the `worker` runs it and its row appears in `job_history`. Like the worker, it waits for `make install` on a fresh checkout.

```bash
docker compose exec scheduler php artisan schedule:list               # the tasks and when they are due
docker compose exec php php artisan schedule:test --name=prune_audit_events   # run one now
docker compose logs --follow scheduler
```

## Web workspace

Node runs in the `node` service as your user, with `web/` mounted at `/workspace/web` and `contracts/` mounted read-only at `/workspace/contracts`. pnpm keeps its store in `web/node_modules/.pnpm-store`, on the same filesystem as the workspace, so packages are hard-linked instead of copied; deleting `web/node_modules` deletes the store too. The image sets it with `pnpm_config_store_dir`, so every directory of the workspace uses it; left to itself, pnpm made a second store in `web/.pnpm-store` when run from `web/packages/ui` (issue #146). The smoke test checks it. The service runs commands only; it serves nothing.

The image pins Node and pnpm to the versions in `web/.nvmrc` and the `packageManager` field of `web/package.json`. The smoke test fails when they differ; change all three together and run `make up`, which rebuilds the image.

The image also holds Chromium's headless shell, installed by Playwright into `/opt/playwright`, for the component tests of `@hostmesh/ui` (decision [0009](../../docs/decisions/0009-shadcn-vue-in-the-ui-package-and-browser-mode-tests.md)). `PLAYWRIGHT_VERSION` in the Dockerfile must equal the `playwright` entry of the catalog in `web/pnpm-workspace.yaml`; the smoke test fails when they differ. Change both together and run `make up`.

```bash
docker compose exec node pnpm check                      # ESLint, vue-tsc, Vitest and both builds
docker compose exec node pnpm lint:fix                   # fix what ESLint can fix
docker compose exec node pnpm install                    # after changing a dependency
docker compose exec node pnpm vitest run --project admin
docker compose exec node pnpm generate:api               # regenerate the typed API client
```

See [`web/README.md`](../../web/README.md).

## Test doubles

ACME and DNS tests must never reach a production service (spec section 37). Two services stand in for them:

| Service        | What it is                                                                               | From your machine                                                      | From the `php` service                                        |
|----------------|------------------------------------------------------------------------------------------|------------------------------------------------------------------------|---------------------------------------------------------------|
| `pebble`       | [Pebble](https://github.com/letsencrypt/pebble) 2.10.1, Let's Encrypt's ACME test server | `https://localhost:14000/dir`; management on `https://localhost:15000` | `https://pebble:14000/dir`; `https://pebble:15000`            |
| `challtestsrv` | Pebble's challenge test server: a mock DNS server with an HTTP management API            | DNS on `127.0.0.1:8053` (UDP and TCP); API on `http://localhost:8055`  | DNS on `challtestsrv:8053`; API on `http://challtestsrv:8055` |

Pebble validates every challenge by asking `challtestsrv`, so a test decides what Pebble sees:

```bash
# An A record, and the DNS server's answer.
curl -d '{"host":"www.example.test","addresses":["192.0.2.10"]}' http://localhost:8055/add-a
dig @127.0.0.1 -p 8053 +short www.example.test A
curl -d '{"host":"www.example.test"}' http://localhost:8055/clear-a

# A DNS-01 answer. The host needs its trailing dot.
curl -d '{"host":"_acme-challenge.cert.example.test.","value":"<token>"}' http://localhost:8055/set-txt
```

The other endpoints (AAAA, CNAME, CAA, SERVFAIL, HTTP-01 and TLS-ALPN-01 answers, request history) are in the [challenge test server's README](https://github.com/letsencrypt/pebble/blob/v2.10.1/cmd/pebble-challtestsrv/README.md). A name that no test added does not resolve: the server runs without default answers.

Things to know:

- **Nothing is kept.** Records, accounts and certificates are gone after a restart, and Pebble makes a new root certificate each time: fetch it from `/roots/0` on the management port, do not store it.
- **Pebble's API uses a certificate from a CA inside its image**, valid for `localhost`, `pebble` and `127.0.0.1`. Copy it out with `docker compose cp pebble:/test/certs/pebble.minica.pem <path>` and give it to the client as its CA file. Never add it to a trust store and never commit it: its private key is public.
- **A test cleans up the records it added**, because the server is shared by every test that runs against the stack.
- Pebble runs with `PEBBLE_VA_NOSLEEP=1` and `PEBBLE_WFE_NONCEREJECT=0`: it validates at once and does not reject valid nonces at random.
- Pebble connects to port 5002 for HTTP-01 and 5001 for TLS-ALPN-01, not 80 and 443. HTTP-01 against a real node needs another Pebble configuration; that comes with Slice 3.

### From the agent's test suite

The agent's tests run on your machine. `make -C agent test-doubles` runs the tests marked `doubles`; `make -C infrastructure/development doubles` starts just the two services they need. `agent/tests/support/doubles.py` holds the addresses and `fetch_pebble_ca`; the fixtures `pebble_ca` and `challenge_api` are in `agent/tests/integration/conftest.py`. `test_acme_double.py` and `test_dns_double.py` there are the examples to copy.

### From the control plane's test suite

Pest runs inside the `php` service, which reaches both doubles by service name (the right-hand column above); the smoke test checks that. Pebble's CA is not in that container: copy it into the test's temporary directory with the `docker compose cp` command above, run from the host, or fetch it once in the test setup. There is no control plane code, configuration or test that uses the doubles yet. The resolver and the ACME client the control plane uses are chosen in Slice 3, and their tests then point them at these addresses.

## End-to-end mode

`make e2e-up` switches the stack to the mode the Playwright suite runs in (`web/README.md`, decision 0016): Caddy serves the built apps from `web/apps/*/dist` with the security headers of `infrastructure/nginx/hostmesh.conf.example` (`Caddyfile.e2e`), and the `php`, `worker` and `scheduler` services run with `APP_ENV=e2e` on the database `hostmesh_e2e` and on Redis databases 2 and 3 (`compose.e2e.yaml`). Your development database is not touched. `make e2e` seeds and runs the suite; `make up` switches back. `composer check` works in either mode: `phpunit.xml` overrides those variables, so the control plane's tests always run as `testing` on `hostmesh_test` and Redis database 15.

The hostnames are the same in both modes, so only one runs at a time. While the stack is in end-to-end mode a browser on your machine sees the built apps and the seeded branding ("Hostmesh E2E"), and the accounts of "Logging in locally" do not exist there.

Chromium in the `node` service trusts Caddy's CA through an NSS database (`$HOME/.pki/nssdb` in that container) that `make e2e-up` rebuilds on every run, so nothing else is needed after a `make reset`.

## PostgreSQL and Redis

Neither is published to the host. Containers in this Compose project reach them as `postgres:5432` and `redis:6379`. The database name, user and password are in `.env`.

From this directory:

```bash
docker compose exec postgres psql -U hostmesh -d hostmesh
docker compose exec redis redis-cli
```

## Logging in locally

Create an administrator with the console command of the control plane. It generates the password and shows it once, so store it; there is no way to read it later.

```bash
docker compose exec php php artisan administrator:create owner@hostmesh.test --name="Owner"
```

The role is `owner` unless `--role=read-only` is given. The first admin login leads to the two-factor setup: scan the QR code with any authenticator app.

Create a customer the same way. The password is generated and shown once too; log in at https://app.hostmesh.test. Two-factor is off until the customer turns it on on the account screen.

```bash
docker compose exec php php artisan customer:create customer@hostmesh.test --name="Customer"
```

Change a password on the account screen of either app. A forgotten one is replaced from the command line: `administrator:reset-password <email>` or `customer:reset-password <email>` prints a new one, once.

## Troubleshooting

**`make up` fails with "address already in use" or "port is already allocated".** Something else holds port 80 or 443 on `127.0.0.1`. Find it with `sudo ss -ltnp | grep -E ':(80|443)\s'` and stop it.

**`make up` waits and then reports a service as unhealthy.** Run `docker compose ps` and `docker compose logs <service>` from this directory.

**Smoke test: "Is the stack up?" or "Connection refused".** Run `make up`.

**Smoke test: "The scheduler runs the audit retention tasks" fails.** The `scheduler` service is not running, or `php artisan schedule:list` in it does not list `prune_audit_events` and `clear_audit_addresses`. Check `docker compose logs scheduler`; on a fresh checkout run `make install` first.

**Smoke test: "No worker ran the job".** The worker did not run a job dispatched to the application's queue within 15 seconds. Check `docker compose logs worker`: it must run `queue:listen` on the connection and queue the control plane dispatches to (`QUEUE_CONNECTION`, `REDIS_QUEUE`). The probe, `php artisan smoke:queue`, exists in development mode only, so this check also fails while the stack is in end-to-end mode; run `make up` first.

**Smoke test: "Name does not resolve".** Run `make hosts`, then check with `getent hosts app.hostmesh.test`.

**Smoke test: "Certificate is not trusted".** Run `make trust`. This is expected after every `make reset`, because a reset creates a new CA.

**The smoke test passes but the browser shows a certificate warning.** See [Browsers](#browsers).

**PostgreSQL rejects the password after you edited `.env`.** PostgreSQL applies `.env` only when its volume is first created. Run `make reset` and `make up`. This deletes the database.

**Caddy cannot read its `Caddyfile` ("permission denied") on a host with SELinux enforcing.** Not tested: the machine this was developed on has SELinux disabled. The usual fix is to change the mount in `compose.yaml` to `./Caddyfile:/etc/caddy/Caddyfile:ro,z`.

**Tests fail with `database "hostmesh_test" does not exist`.** The database is created with the PostgreSQL volume. A volume from before the control plane existed does not have it. Create it without losing data: `docker compose exec postgres createdb -U hostmesh hostmesh_test`.

**The contract test says `contracts/api/openapi.json is missing`, and `composer openapi` fails with `file_put_contents(/var/www/html/../contracts/api/openapi.json): Failed to open stream`.** The `php` container was created before `compose.yaml` mounted `contracts/` into it, so `../contracts` does not exist there. Run `make up`: it recreates the container with the current mounts. Check with `docker compose exec php ls ../contracts`.

**Files in `control-plane/` are owned by root.** The `php` service was started without the Makefile, which sets your user and group id. Run `make down`, fix the ownership with `sudo chown -R "$(id -u):$(id -g)" ../../control-plane`, and start with `make up`.

**`make e2e`: "The stack is not in end-to-end mode".** Run `make e2e-up`.

**The apps show "Hostmesh E2E" or your login no longer works.** The stack is in end-to-end mode. Run `make up`.

**`make e2e-up` fails with `database "hostmesh_e2e" does not exist`.** Run it again; it creates the database on a volume that is older than the init script.

## Undo

```bash
make -C infrastructure/development reset
sudo sed -i '/^# BEGIN hostmesh development$/,/^# END hostmesh development$/d' /etc/hosts
# Fedora, RHEL:
sudo rm /etc/pki/ca-trust/source/anchors/hostmesh-development-ca.crt && sudo update-ca-trust
# Debian, Ubuntu:
sudo rm /usr/local/share/ca-certificates/hostmesh-development-ca.crt && sudo update-ca-certificates --fresh
```
