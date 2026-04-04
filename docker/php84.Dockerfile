FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
        zlib1g-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install zip intl mbstring pdo pdo_mysql opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.enable_cli=0"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.max_accelerated_files=10000"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.jit=tracing"; \
    echo "opcache.jit_buffer_size=64M"; \
} > /usr/local/etc/php/conf.d/opcache-jit.ini

WORKDIR /var/www
