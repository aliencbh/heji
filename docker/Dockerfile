FROM composer:latest AS composer
FROM php:fpm-alpine AS base
RUN apk add --no-cache unzip libpq libpng libjpeg-turbo freetype libxml2 oniguruma icu-libs fcgi tini libssl3 libcrypto3 zlib \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS libpq-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev libxml2-dev icu-dev linux-headers curl \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j2 pdo_pgsql gd bcmath intl pcntl \
 && mkdir -p /tmp/redis-src \
 && curl -fsSL https://pecl.php.net/get/redis -o /tmp/redis.tgz \
 && tar -xzf /tmp/redis.tgz --strip-components=1 -C /tmp/redis-src \
 && cd /tmp/redis-src \
 && phpize \
 && ./configure \
 && make -j"$(nproc)" \
 && make install \
 && docker-php-ext-enable redis \
 && cd / \
 && rm -rf /tmp/redis-src /tmp/redis.tgz \
 && apk del .build
ADD https://ftp.postgresql.org/pub/source/ /tmp/pg-index.html
RUN apk add --no-cache --virtual .pg-build build-base openssl-dev zlib-dev linux-headers perl curl bison flex \
 && PG_VERSION="$(grep -oE 'v[0-9]+\.[0-9]+/' /tmp/pg-index.html | tr -d 'v/' | sort -t. -k1,1n -k2,2n | tail -n1)" \
 && test -n "$PG_VERSION" \
 && cd /tmp \
 && curl -fsSL -o "postgresql-${PG_VERSION}.tar.gz" "https://ftp.postgresql.org/pub/source/v${PG_VERSION}/postgresql-${PG_VERSION}.tar.gz" \
 && curl -fsSL "https://ftp.postgresql.org/pub/source/v${PG_VERSION}/postgresql-${PG_VERSION}.tar.gz.sha256" | sha256sum -c - \
 && tar -xzf "postgresql-${PG_VERSION}.tar.gz" \
 && cd "postgresql-${PG_VERSION}" \
 && ./configure --prefix=/usr/local --with-openssl --without-readline --without-icu --without-lz4 --without-zstd \
 && make -C src/interfaces/libpq -j"$(nproc)" install \
 && make -C src/bin/pg_dump -j"$(nproc)" install \
 && make -C src/bin/psql -j"$(nproc)" install \
 && cd / \
 && rm -rf /tmp/pg-index.html "/tmp/postgresql-${PG_VERSION}" "/tmp/postgresql-${PG_VERSION}.tar.gz" /usr/local/lib/libpq.a \
 && apk del .pg-build \
 && pg_dump --version && pg_restore --version && psql --version
WORKDIR /srv/heji
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
FROM base AS dependencies
COPY composer.* ./
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
RUN apk add --no-cache --virtual .test-build $PHPIZE_DEPS linux-headers curl \
 && curl -fsSL https://xdebug.org/files/xdebug-3.6.0alpha1.tgz -o /tmp/xdebug.tgz \
 && mkdir -p /tmp/xdebug-src \
 && tar -xzf /tmp/xdebug.tgz --strip-components=1 -C /tmp/xdebug-src \
 && cd /tmp/xdebug-src \
 && phpize \
 && ./configure \
 && make -j"$(nproc)" \
 && make install \
 && docker-php-ext-enable xdebug \
 && cd /srv/heji \
 && rm -rf /tmp/xdebug-src /tmp/xdebug.tgz \
 && apk del .test-build \
 && composer install --prefer-dist --no-interaction --no-scripts --optimize-autoloader \
 && chown -R www-data:www-data bootstrap/cache storage
ENV XDEBUG_MODE=coverage
USER www-data
FROM nginx:stable-alpine AS web
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY --from=app /srv/heji/public /srv/heji/public
FROM mcr.microsoft.com/playwright/python:latest AS browser
RUN pip install --no-cache-dir playwright
COPY tests/browser.py tests/ui_controls.py tests/controls.test.cjs /checks/tests/
COPY resources/assets /checks/resources/assets
CMD ["sh", "-ec", "node_path=$(python -c 'import pathlib,playwright;print(pathlib.Path(playwright.__file__).parent/\"driver/node\")'); \"$node_path\" --test /checks/tests/controls.test.cjs && python /checks/tests/ui_controls.py && python /checks/tests/browser.py"]

FROM alpine:latest AS setup
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
