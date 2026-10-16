FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs --prefer-dist --no-interaction
COPY src ./src
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-benchmark.ini
COPY docker/sync/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY benchmark/sync ./benchmark/sync
