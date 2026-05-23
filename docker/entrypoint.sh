#!/bin/sh
set -eu

mkdir -p "${STORAGE_ROOT:-/var/lib/tiny-s3}" "$(dirname "${LOG_FILE:-/var/log/tiny-s3/activities.log}")"
chown -R www-data:www-data "${STORAGE_ROOT:-/var/lib/tiny-s3}" "$(dirname "${LOG_FILE:-/var/log/tiny-s3/activities.log}")" || true

php-fpm -D
exec nginx -g 'daemon off;'
