# syntax=docker/dockerfile:1

# Tiny S3 runtime image.
# Uses the official PHP FPM Alpine image and adds Nginx as the HTTP front end.
FROM php:8.5-fpm-alpine

LABEL org.opencontainers.image.title="Tiny S3"
LABEL org.opencontainers.image.description="Minimal AWS S3-compatible storage emulator written in pure PHP"
LABEL org.opencontainers.image.licenses="MIT"

RUN apk add --no-cache nginx curl \
    && mkdir -p /run/nginx /var/www/html /var/lib/tiny-s3 /var/log/tiny-s3 \
    && chown -R www-data:www-data /var/www/html /var/lib/tiny-s3 /var/log/tiny-s3

WORKDIR /var/www/html

COPY --chown=www-data:www-data index.php composer.json composer.lock LICENSE README.md ./
COPY --chown=www-data:www-data .env.template ./
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-tiny-s3.ini
COPY docker/entrypoint.sh /usr/local/bin/tiny-s3-entrypoint

RUN chmod +x /usr/local/bin/tiny-s3-entrypoint

ENV DEBUG=false \
    REGION=us-east-1 \
    ALLOWED_IPS= \
    STORAGE_ROOT=/var/lib/tiny-s3 \
    LOG_FILE=/var/log/tiny-s3/activities.log

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD curl -fsS http://127.0.0.1:8080/healthz || exit 1

ENTRYPOINT ["tiny-s3-entrypoint"]
