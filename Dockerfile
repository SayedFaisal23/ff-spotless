# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM php:8.3-cli AS vendor

WORKDIR /app

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install --yes --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

COPY . ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && php artisan package:discover --ansi

FROM node:22-alpine AS frontend

WORKDIR /app

COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci --ignore-scripts --no-audit --no-fund; else npm install --ignore-scripts --no-audit --no-fund; fi

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./
RUN npm run build

FROM php:8.3-apache AS application

WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install --yes --no-install-recommends libjpeg62-turbo-dev libpng-dev libsqlite3-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_mysql pdo_sqlite opcache \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/laravel.conf /etc/apache2/conf-available/laravel.conf
RUN a2enconf laravel \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

COPY . ./
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data bootstrap/cache storage

EXPOSE 80

CMD ["apache2-foreground"]
