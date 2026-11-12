# syntax=docker/dockerfile:1
FROM php:8.2-apache-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libonig5 unzip \
    && docker-php-ext-install -j"$(nproc)" mbstring pdo_mysql opcache \
    && apt-get purge -y --auto-remove libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf

WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/picklers.ini
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

FROM base AS dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

FROM base AS production
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY composer.json composer.lock ./
COPY bin ./bin
COPY config ./config
COPY src ./src
COPY templates ./templates
COPY public ./public
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && composer check-platform-reqs --no-dev \
    && mkdir -p var storage/permits database public/uploads/facilities /var/lib/php/sessions \
    && chown -R www-data:www-data var storage database public/uploads /var/run/apache2 /var/lock/apache2 /var/lib/php/sessions
ENV APP_ENV=prod APP_DEBUG=0
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$c=stream_context_create(["http"=>["timeout"=>3]]); exit(@file_get_contents("http://127.0.0.1:8080/",false,$c)===false?1:0);'
CMD ["apache2-foreground"]

FROM node:24-bookworm-slim AS node

FROM production AS development
USER root
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-client \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY scripts ./scripts
COPY tests ./tests
COPY phpunit.xml.dist ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts \
    && composer dump-autoload --optimize --no-scripts \
    && composer check-platform-reqs
ENV APP_ENV=dev APP_DEBUG=1
USER www-data

# Keep the default build free of development dependencies and test tooling.
FROM production AS final
