# syntax=docker/dockerfile:1
#
# pmp-php: PHP 8.3 on Alpine, with Composer and PHPMailer-ready extensions.
# Used by docker-compose.yml for the "app" service.

FROM php:8.3-fpm-alpine

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# System packages needed at build-time (icu-dev, libzip-dev, oniguruma-dev) AND
# runtime (icu-libs). docker-php-ext-install needs $PHPIZE_DEPS.
RUN apk add --no-cache \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        $PHPIZE_DEPS \
        git \
        unzip \
        curl \
        bash \
        nginx \
        supervisor \
        icu-libs \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        intl \
        opcache \
        zip \
    && apk del icu-dev \
    && rm -rf /tmp/* /var/cache/apk/*

# Install Composer inline (we cannot rely on pulling composer:2 image in this
# environment, see docker-networking notes).
RUN curl -fsSL -o /usr/bin/composer \
        https://getcomposer.org/download/2.7.7/composer.phar \
    && chmod +x /usr/bin/composer \
    && composer --version

# Production php.ini overrides
RUN { \
        echo 'memory_limit=256M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
        echo 'date.timezone=UTC'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
    } > /usr/local/etc/php/conf.d/zz-pmp.ini

# Working directory matches the project's webroot.
WORKDIR /var/www/html

# Copy composer manifests first so layer caches `composer install` when source
# code changes but dependencies don't.
COPY composer.json composer.lock* ./

# Only run `composer install` when the vendor directory isn't already present
# (e.g. when a developer mounts the project as a bind mount for live editing).
RUN if [ ! -d vendor ]; then \
        composer install --no-dev --prefer-dist --no-scripts --no-autoloader || true; \
    fi

COPY . .

# If vendor is still missing (e.g. bind mount overwrote it at runtime), ensure
# PHPMailer is installed by name as a fallback.
RUN if [ ! -d vendor/phpmailer/phpmailer ]; then \
        composer require --no-update phpmailer/phpmailer:^6.8 || true; \
        composer install --no-dev --prefer-dist --no-scripts || true; \
    fi \
    && composer dump-autoload --optimize --no-dev || true

# Entrypoint that writes config.php from env vars before php-fpm starts.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]