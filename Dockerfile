FROM php:8.5-fpm-alpine

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# System packages needed at build-time (icu-dev, libzip-dev, oniguruma-dev) AND
# runtime (icu-libs). docker-php-ext-install needs $PHPIZE_DEPS.
#
# NOTE: opcache is deliberately NOT in the ext-install list. As of PHP 8.5 the
# upstream base image compiles opcache STATICALLY into the PHP binary
# (`php -m` shows "Zend OPcache"; there is no opcache.so in the extension dir),
# and ext/opcache/config.m4 now declares it as a non-shared module. Running
# `docker-php-ext-install opcache` against 8.5 therefore builds nothing and
# fails at `make install` with "cp: can't stat 'modules/*'". The php.ini
# overrides below (opcache.enable, memory_consumption, max_accelerated_files)
# still apply to the static build.
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

# Copy composer manifests. We do NOT run `composer install` at build time because
# the bind-mount in docker-compose.yml would wipe anything we put in
# /var/www/html at runtime. The entrypoint installs dependencies after the
# volume is mounted (and only if vendor/ is missing) so failures surface
# instead of being swallowed.
COPY composer.json composer.lock* ./
COPY . .

# Entrypoint that writes config.php from env vars and ensures vendor/ exists
# before php-fpm starts.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]