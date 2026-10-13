# SPDX-License-Identifier: Apache-2.0
#
# PHP runtime for local development of the control plane. See
# docs/decisions/0003-development-runtimes-in-containers.md and
# docs/decisions/0004-php-8-5-for-the-control-plane.md.

FROM composer:2.10.3 AS composer

FROM php:8.5.11-fpm-trixie

# The base image already has the extensions Laravel 13 requires. Added here:
# pdo_pgsql (PostgreSQL), redis (queue and cache client), pcntl (queue
# workers), intl, and pcov (code coverage).
RUN apt-get update \
    && apt-get install --yes --no-install-recommends git unzip libpq-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql intl pcntl \
    && pecl install redis-6.3.0 pcov-1.0.12 \
    && docker-php-ext-enable redis pcov \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer /usr/bin/composer /usr/bin/composer

# The container runs as the host user, who has no home directory inside it.
ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html
