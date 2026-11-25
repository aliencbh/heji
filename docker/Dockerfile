FROM composer:2 AS composer
FROM php:8.4-fpm-alpine3.23 AS base
RUN apk add --no-cache unzip postgresql18-client libpq libpng libjpeg-turbo freetype libxml2 oniguruma icu-libs fcgi tini \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS libpq-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev libxml2-dev icu-dev linux-headers \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_pgsql gd bcmath intl pcntl \
 && pecl install redis-6.3.0 && docker-php-ext-enable redis \
 && apk del .build
WORKDIR /srv/heji
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
FROM base AS dependencies
COPY --chmod=0644 composer.* ./
RUN if [ ! -e composer.lock ]; then composer update --no-install --no-scripts --no-interaction --prefer-dist; fi \
 && composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader
FROM base AS app
COPY . .
COPY --from=dependencies /srv/heji/vendor ./vendor
COPY --from=dependencies /srv/heji/composer.lock ./composer.lock
COPY docker/php.ini /usr/local/etc/php/conf.d/99-heji.ini
COPY docker/pool.conf /usr/local/etc/php-fpm.d/zzzz-heji.conf
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
 && mkdir -p bootstrap/cache storage/framework/views storage/framework/cache/data storage/framework/sessions storage/app/private \
 && for d in laravel php nginx redis postgres scheduler runtime tests; do mkdir -p storage/logs/$d; done \
 && for s in shop stock recipe; do mkdir -p public/$s/assets && cp resources/assets/* public/$s/assets/; done \
 && chown -R www-data:www-data storage bootstrap/cache && chmod +x bin/* artisan
USER www-data
ENTRYPOINT ["/sbin/tini","--"]
CMD ["php-fpm","-F"]
FROM app AS test
USER root
RUN apk add --no-cache --virtual .test-build $PHPIZE_DEPS linux-headers \
 && pecl install xdebug && docker-php-ext-enable xdebug && apk del .test-build \
 && composer install --prefer-dist --no-interaction --no-scripts --optimize-autoloader \
 && chown -R www-data:www-data bootstrap/cache storage
ENV XDEBUG_MODE=coverage
USER www-data
FROM nginx:stable-alpine AS web
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY --from=app /srv/heji/public /srv/heji/public
FROM mcr.microsoft.com/playwright/python:v1.56.0-noble AS browser
RUN pip install --no-cache-dir playwright==1.56.0
COPY tests/browser.py tests/ui_controls.py tests/controls.test.cjs /checks/tests/
COPY resources/assets /checks/resources/assets
CMD ["sh", "-ec", "node_path=$(python -c 'import pathlib,playwright;print(pathlib.Path(playwright.__file__).parent/\"driver/node\")'); \"$node_path\" --test /checks/tests/controls.test.cjs && python /checks/tests/ui_controls.py && python /checks/tests/browser.py"]

FROM alpine:3.23 AS setup
RUN apk add --no-cache bash python3 openssl flock coreutils
WORKDIR /opt/heji
COPY .env.example ./
COPY --chmod=0755 docker/init ./init
ENTRYPOINT ["/opt/heji/init"]

FROM docker:cli AS operations
RUN apk add --no-cache bash python3 openssl curl coreutils tar flock git diffutils dbus \
 && command -v flock && command -v dbus-send && docker compose version && docker buildx version
COPY .env.example /opt/heji/.env.example
COPY --chmod=0755 docker/init docker/ops /opt/heji/
ENTRYPOINT ["/opt/heji/ops"]
CMD ["help"]

FROM fluent/fluent-bit:latest AS log-collector
COPY docker/fluent-bit.conf /fluent-bit/etc/heji.conf
ENTRYPOINT ["/fluent-bit/bin/fluent-bit"]
CMD ["-c", "/fluent-bit/etc/heji.conf"]
