FROM php:8.5-cli-alpine

RUN apk add --no-cache \
    libzip-dev \
    zlib-dev \
    libxml2-dev \
    curl-dev \
    git \
    unzip \
    pkgconfig

RUN docker-php-ext-install bcmath curl soap zip pcntl

WORKDIR /usr/src/crawler

COPY . .
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --optimize-autoloader

HEALTHCHECK --interval=60s --timeout=10s --start-period=10m --retries=3 \
    CMD ["php", "healthcheck.php", "/data/public", "900"]

CMD ["sh", "init.sh"]
