# syntax=docker/dockerfile:1

FROM composer:2.7.7 AS composer_bin

FROM php:8.2.20-fpm-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        libzip-dev \
        libsqlite3-dev \
        unzip \
        curl \
        gettext-base \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer_bin /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

FROM base AS deps
COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-scripts --no-interaction --prefer-dist

FROM deps AS test
COPY backend/ ./
COPY migrations/ /var/www/migrations/
COPY tests/ /var/www/tests/
RUN composer install --no-scripts --no-interaction --prefer-dist
CMD ["php", "/var/www/app/vendor/bin/phpunit", "-c", "/var/www/tests/phpunit.xml"]

FROM base AS runtime
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-scripts --no-interaction --prefer-dist --no-dev \
    && composer clear-cache

COPY backend/ ./
COPY migrations/ /var/www/migrations/
COPY frontend/ /var/www/frontend/

COPY docker/app/nginx.conf.template /etc/nginx/templates/nginx.conf.template
COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-circuitmap.ini
RUN rm -f /usr/local/etc/php-fpm.d/zz-docker.conf /usr/local/etc/php-fpm.d/www.conf.default
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/app/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/app/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

RUN mkdir -p /var/lib/circuitmap/db /var/lib/circuitmap/kml /run/php \
    && chown -R www-data:www-data /var/www/app /var/www/frontend /var/lib/circuitmap /run/php

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
