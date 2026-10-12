# License hygiene

The dependency license scan, the generated `THIRD_PARTY_NOTICES` and the SPDX identifier check (spec section 36, decision 0001). `hostmesh_hygiene` is the Python module behind all three; it needs nothing but the standard library.

## Setup

`make -C infrastructure/hygiene install`, once. The scan and the notices also read what the other projects have installed, so they need the development stack running with its dependencies (`make -C infrastructure/development up`) and `make install` in `agent/` and `infrastructure/testing/`. uv 0.12 on the host, as for the agent.

| Task                                              | Command                                         |
|---------------------------------------------------|-------------------------------------------------|
| The tool's own checks (ruff, mypy strict, pytest) | `make -C infrastructure/hygiene check`          |
| License scan                                      | `make -C infrastructure/hygiene licenses`       |
| Regenerate `THIRD_PARTY_NOTICES`                  | `make -C infrastructure/hygiene notices`        |
| Check that it is current                          | `make -C infrastructure/hygiene notices-check`  |

## The license scan

Hostmesh is Apache-2.0 (decision 0001). Every Composer, npm and Python dependency, development dependencies included, must be usable under one of the licenses in `allowed` in [`licenses.toml`](licenses.toml): MIT, BSD-2-Clause, BSD-3-Clause, Apache-2.0, ISC, 0BSD, CC0-1.0, Unlicense, and MPL-2.0 (for dependencies only; nothing of Hostmesh's own is MPL). The job "License scan" of `.github/workflows/hygiene.yml` runs the scan on every pull request.

How it decides:

- The listings come from each ecosystem's own tool: `composer licenses`, `pnpm --recursive licenses list`, and the metadata of the packages installed in the three uv projects (`agent/`, `infrastructure/testing/`, `infrastructure/hygiene/`). `make collect` writes them to `.inputs/`.
- A license is read as an SPDX expression. `MIT OR GPL-3.0-only` passes, because MIT may be chosen; `MIT AND GPL-3.0-only` does not. Composer's list of several licenses is a choice.
- A package that declares no license, or text that is not an SPDX expression, fails.
- A missing or empty listing is an error (exit code 2), never a pass.

### When the scan fails

In this order:

1. **Do without the dependency**, or pick an alternative with an allowed license.
2. **If it is a permissive license that is merely not on the list**, record an exception in `licenses.toml`:

   ```toml
   [[exceptions]]
   ecosystem = "npm"            # composer, npm or python
   package = "lru-cache"
   license = "BlueOak-1.0.0"    # exactly what the package declares
   reason = "Why this license is acceptable, and whether the package ships or is development only."
   ```

   The reason is mandatory and is reviewed in the pull request like code. An exception covers one package under one license: if the package changes its license, the scan fails again. An exception that no longer matches an installed package fails the scan too, so remove it in the pull request that removes the dependency.
3. **Copyleft (GPL, LGPL, AGPL, EUPL, SSPL), source-available or proprietary licenses** are not a matter for an exception. Open an issue labelled `needs-decision`; the answer may be a decision record.

To allow a license for every package, add it to `allowed`. That changes the policy and needs the maintainer's agreement.

### Services that are not linked

The scan covers what Hostmesh links against or ships. It does not cover the services that Hostmesh installs, configures and talks to as separate processes, over a socket or a network protocol or through their configuration files:

| Service                       | License                                    | How Hostmesh uses it                                              |
|-------------------------------|--------------------------------------------|-------------------------------------------------------------------|
| PowerDNS Authoritative Server | GPL-2.0-only                               | The agent reconciles zones through its HTTP API on the DNS node   |
| MariaDB Server                | GPL-2.0-only                               | The agent creates databases and users through the client protocol |
| Nginx                         | BSD-2-Clause                               | The agent writes configuration files and reloads the service      |
| PHP-FPM                       | PHP-3.01                                   | The agent writes pool configuration; customer code runs in it     |
| PostgreSQL                    | PostgreSQL                                 | The control plane's database, over the client protocol            |
| Redis                         | AGPL-3.0-only, RSALv2 or SSPLv1 (from 8.0) | Queues, cache and rate limiting, over the client protocol         |

They are separate programs, installed from the distribution's or the vendor's packages; Hostmesh neither contains nor links their code, so their licenses place no condition on Hostmesh's. Client libraries are a different matter: they are linked, and the scan covers them. This table is not legal advice (decision 0001 recommends a legal review before the repository is published).

Before committing, verify each license in the table against the project's own repository or website (CLAUDE.md: "Verify current … documentation"; licenses change, Redis' did twice). Correct any cell that is out of date. The versions in use are in `infrastructure/development/compose.yaml` and spec section 14.

## THIRD_PARTY_NOTICES

MIT, BSD, ISC and Apache-2.0 require that the copyright notice and the license text travel with copies of the software. `THIRD_PARTY_NOTICES` at the repository root reproduces the license and notice files of:

- third-party source code that lives in the repository, listed under `[[vendored]]` in `licenses.toml` (the shadcn-vue components of `@hostmesh/ui`, and the files that come from the Laravel application skeleton);
- the **production** dependencies: `composer licenses --no-dev`, `pnpm --recursive licenses list --prod`, and the agent's `uv export --no-dev`. Development-only dependencies are scanned but not listed, because nothing ships them.

The file is generated and never edited. It prints no version numbers, so it changes only when a production dependency is added, removed, or changes its license files. When that happens the job "License scan" fails with "THIRD_PARTY_NOTICES is out of date": run `make -C infrastructure/hygiene notices` and commit the result. On a Dependabot pull request, check the branch out, run the command and push the commit (signed off) to it.

Generate it on Linux x86-64, the platform of CI and of the development stack's containers: a package manager on another platform may install a different set of optional packages.

`NOTICE` is the short, hand-written attribution file that Apache-2.0 section 4(d) refers to. It points here.
