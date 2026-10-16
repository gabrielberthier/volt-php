FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs --prefer-dist --no-interaction
COPY src ./src
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.4-cli

# pgsql: required by amphp/postgres. pcntl/posix: required by PHPStreamServer.
# event: libevent loop for Revolt (stream_select is capped at 1024 fds and slower).
# Not ev: amphp/postgres refuses ext-pgsql on the ev loop.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libevent-dev libssl-dev $PHPIZE_DEPS \
    && docker-php-ext-install pgsql pcntl sockets opcache \
    && pecl install event \
    && docker-php-ext-enable --ini-name zz-event.ini event \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-benchmark.ini

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY src ./src
COPY benchmark/async ./benchmark/async

EXPOSE 8080
CMD ["php", "benchmark/async/server.php"]
