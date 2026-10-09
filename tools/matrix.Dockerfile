ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash default-mysql-client git less libcurl4-openssl-dev libicu-dev \
        libonig-dev libxml2-dev libzip-dev unzip zip zlib1g-dev \
    && docker-php-ext-install -j2 bcmath curl dom intl mbstring mysqli zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /workspace
