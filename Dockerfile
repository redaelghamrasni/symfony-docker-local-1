FROM php:8.4-fpm-alpine AS base

# Necessary packages for PHP extensions and other tools
RUN apk add --no-cache \
    nginx \
    nodejs \
    npm \
    git \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    postgresql-dev \
    linux-headers \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        intl \
        zip \
        opcache \
        mbstring \
        sockets

# Redis Extension
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# AMQP Extension (RabbitMQ)
RUN apk add --no-cache rabbitmq-c-dev \
    && apk add --no-cache --virtual .amqp-build-deps $PHPIZE_DEPS \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apk del .amqp-build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ── Build ──────────────────────────────────────────────────────────────
FROM base AS build

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

COPY . .

# APP_ENV=prod is forced here: the image installs with --no-dev, so booting the kernel in
# dev (the default in the committed .env) would fail on the dev-only DebugBundle.
ENV APP_ENV=prod APP_DEBUG=0

RUN composer dump-autoload --optimize --no-dev
RUN npm run build
RUN php bin/console tailwind:build --minify
RUN php bin/console importmap:install
RUN php bin/console asset-map:compile

# ── Production ─────────────────────────────────────────────────────────
FROM base AS production

COPY --from=build /var/www/html /var/www/html
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/entrypoint.sh /entrypoint.sh

RUN mkdir -p /var/www/html/var \
    && chmod +x /entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/var \
    && mkdir -p /run/nginx

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
