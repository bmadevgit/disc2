FROM composer:2 AS composer
FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip git gpg \
    && docker-php-ext-install intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
# Docker copies this directory's ownership into an empty vendor volume.
ARG DISC2_UID=1000
ARG DISC2_GID=1000
RUN install -d -o "$DISC2_UID" -g "$DISC2_GID" /app/vendor
