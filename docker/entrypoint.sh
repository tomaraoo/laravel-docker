#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" = "0" ]; then
    if [ -n "${PHP_GROUP_ID:-}" ] && [ "$(id -g www-data)" != "${PHP_GROUP_ID}" ]; then
        groupmod -o -g "${PHP_GROUP_ID}" www-data
    fi

    if [ -n "${PHP_USER_ID:-}" ] && [ "$(id -u www-data)" != "${PHP_USER_ID}" ]; then
        usermod -o -u "${PHP_USER_ID}" www-data
    fi

    if [ -f /app/artisan ]; then
        for writable_dir in \
            /app/storage/app/public \
            /app/storage/framework/cache/data \
            /app/storage/framework/sessions \
            /app/storage/framework/views \
            /app/storage/logs \
            /app/bootstrap/cache; do
            if [ ! -d "${writable_dir}" ]; then
                mkdir -p "${writable_dir}"
                chown www-data:www-data "${writable_dir}" 2>/dev/null || true
            fi
        done
    fi
fi

exec docker-php-entrypoint "$@"
