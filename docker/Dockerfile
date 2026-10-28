# syntax=docker/dockerfile:1.7
# ===========================================================================
# Laravel Shared Runtime Image (PHP 8.5 + Apache)
# — Patched from SubayBAYAN's MOUNT-approach PHP 8.5 / PHP2Shell remediation.
#
# MOUNT approach: this image is the PATCHED PHP 8.5 ENGINE + Apache + Laravel
# vhost ONLY. It carries ZERO application code and ZERO credentials.
# Any Laravel project's source tree, vendor/, storage/, and .env(.local) live
# on the host and are bind-mounted into the container at /app at runtime.
# ===========================================================================
FROM php:8.5-apache

# --- Install Laravel PHP extensions & runtime libraries (OPcache is built-in in PHP 8.5).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        curl \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
        zip; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Run Apache's worker user as the SAME uid/gid that owns the host tree so
#     the container can write storage/ and bootstrap/cache/ WITHOUT chowning the host tree.
#     Defaults match RHEL/Rocky prod/staging hosts (apache = 48:48); local dev compose
#     overrides these via build.args and runtime env to match the developer's UID/GID.
ARG HOST_UID=48
ARG HOST_GID=48
ARG HOST_EXTRA_GID=48
ENV PHP_USER_ID=${HOST_UID} \
    PHP_GROUP_ID=${HOST_GID}

RUN set -eux; \
    groupmod -o -g "${HOST_GID}" www-data; \
    usermod -o -u "${HOST_UID}" -g "${HOST_GID}" www-data; \
    getent group "${HOST_EXTRA_GID}" >/dev/null 2>&1 || groupadd -o -g "${HOST_EXTRA_GID}" appextra; \
    usermod -aG "${HOST_EXTRA_GID}" www-data; \
    chown -R "${HOST_UID}:${HOST_GID}" /var/www 2>/dev/null || true

# --- Apache modules for Laravel clean URLs and security headers (HTTP :80 only; TLS upstream).
RUN a2enmod rewrite headers

# --- Runtime PHP hardening & PHP 8.5 deprecation safety.
RUN { \
        echo 'expose_php = Off'; \
        echo 'memory_limit = 512M'; \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 64M'; \
        echo 'max_file_uploads = 20'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.interned_strings_buffer = 16'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 1'; \
        echo 'error_reporting = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED'; \
    } > /usr/local/etc/php/conf.d/zz-laravel-runtime.ini

# --- Baked Laravel vhost (DocumentRoot /app/public, upload hardening, LimitRequestBody).
COPY ./vhost.conf /etc/apache2/sites-available/000-default.conf
COPY ./entrypoint.sh /usr/local/bin/laravel-docker-entrypoint
RUN chmod +x /usr/local/bin/laravel-docker-entrypoint

# --- /app is the mount point for any host Laravel project.
WORKDIR /app

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/laravel-docker-entrypoint"]
CMD ["apache2-foreground"]
