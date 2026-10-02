# 0004. PHP 8.5 for the control plane

- Status: accepted
- Date: 2026-10-01
- Issue: Control plane skeleton (#12)

## Context

The control plane is a Laravel 13 application tested with Pest 5 (spec sections 30 and 37). The PHP version is fixed by the `php` service of the development stack, by `composer.json` and by CI, so it has to be chosen once.

Checked on 2026-10-01: Laravel 13 supports PHP 8.3 to 8.5. Pest 5 requires PHP 8.4 or later, and PHPUnit 13, on which it is built, requires 8.4.1 or later. PHP 8.4 leaves active support on 31 December 2026 and receives security fixes until the end of 2028. PHP 8.5 has active support until the end of 2027 and security fixes until the end of 2029.

## Options considered

- **PHP 8.3.** Excluded by Pest 5.
- **PHP 8.4.** The lowest version that works. It gets bug fixes for three more months.
- **PHP 8.5.** The newest version Laravel 13 supports, with active support for the whole planned development period.

## Decision

The control plane targets PHP 8.5: `"php": "^8.5"` in `control-plane/composer.json`, the `php:8.5.11-fpm-trixie` image in development, and PHP 8.5 in CI.

Extensions beyond Laravel's required list: `pdo_pgsql`, `redis` (phpredis, Laravel's default Redis client, so no Composer package is needed), `pcntl` (queue workers), `intl`, and PCOV for code coverage.

## Consequences

- Operators need PHP 8.5 on the control-plane host. The production image is not part of this decision.
- The patch version of the development image is pinned and is raised deliberately.
- Moving to the next PHP release is a new decision once Laravel and Pest support it.
