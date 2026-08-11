# syntax=docker/dockerfile:1.7

# ─────────────────────────────────────────────────────────────────────────────
# Stage 1 — base: PHP 8.4 FPM with every extension the platform needs.
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.4-fpm-alpine AS base

ARG UID=1000
ARG GID=1000

ENV COMPOSER_ALLOW_SUPERUSER=0 \
    COMPOSER_MEMORY_LIMIT=-1 \
    PHP_INI_DIR=/usr/local/etc/php

RUN apk add --no-cache \
        bash \
        curl \
        git \
        icu-data-full \
        icu-libs \
        libpng \
        libpq \
        libzip \
        oniguruma \
        postgresql-client \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpng-dev \
        libzip-dev \
        linux-headers \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-configure gd --with-jpeg=no \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini            $PHP_INI_DIR/conf.d/zz-asids.ini
COPY docker/php/opcache.ini        $PHP_INI_DIR/conf.d/zz-opcache.ini
COPY docker/php/www.conf           /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh      /usr/local/bin/asids-entrypoint

RUN addgroup -g ${GID} -S asids \
    && adduser -u ${UID} -S asids -G asids -h /home/asids -s /bin/bash \
    && mkdir -p /home/asids/.composer/cache /var/www/html \
    && chown -R asids:asids /home/asids /var/www/html \
    && chmod +x /usr/local/bin/asids-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["asids-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2 — development: Xdebug available on demand, sources bind-mounted.
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS development

# PHP_INI_SCAN_DIR is emptied for this one command so PEAR's OS_Guess can call
# popen() during dependency resolution — the hardened php.ini (zz-asids.ini)
# disables popen, which otherwise makes `pecl install` fatal. Runtime hardening
# is unaffected; the restriction still applies once the container boots.
RUN apk add --no-cache --virtual .xdebug-deps $PHPIZE_DEPS linux-headers \
    && PHP_INI_SCAN_DIR="" pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del .xdebug-deps \
    && rm -rf /tmp/pear

# Xdebug is installed but inert until XDEBUG_MODE is set, so day-to-day
# development keeps full speed.
ENV XDEBUG_MODE=off
COPY docker/php/xdebug.ini $PHP_INI_DIR/conf.d/zz-xdebug.ini

USER asids

# ─────────────────────────────────────────────────────────────────────────────
# Stage 3 — vendor: production Composer dependencies only.
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS vendor

USER asids
COPY --chown=asids:asids composer.json composer.lock ./
RUN --mount=type=cache,target=/home/asids/.composer/cache,uid=${UID},gid=${GID} \
    composer install \
        --no-dev \
        --no-autoloader \
        --no-scripts \
        --no-interaction \
        --prefer-dist

# ─────────────────────────────────────────────────────────────────────────────
# Stage 4 — assets: compiled front-end bundle.
# ─────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS assets

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY tsconfig.json vite.config.ts tailwind.config.ts postcss.config.js ./
COPY resources ./resources
RUN npm run build

# ─────────────────────────────────────────────────────────────────────────────
# Stage 5 — production: immutable, pre-warmed, non-root image.
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS production

ENV APP_ENV=production \
    APP_DEBUG=false

USER asids

COPY --chown=asids:asids --from=vendor  /var/www/html/vendor      ./vendor
COPY --chown=asids:asids --from=assets  /build/public/build       ./public/build
COPY --chown=asids:asids . .

# Regenerate the optimised class map now that application sources are present,
# then bake the framework caches into the image so no container does it at boot.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan config:clear \
    && php artisan event:cache \
    && php artisan route:cache \
    && php artisan view:cache

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'
