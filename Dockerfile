FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip curl nginx libpq-dev libzip-dev postgresql-client \
    && docker-php-ext-install pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend
COPY backend/ /var/www/backend
COPY deploy/nginx.conf /etc/nginx/sites-enabled/app.conf
COPY deploy/php-fpm-env.conf /usr/local/etc/php-fpm.d/zz-env.conf
COPY deploy/web-serve.sh /usr/local/bin/web-serve

RUN chmod +x /usr/local/bin/web-serve \
    && composer install --no-dev --no-interaction --prefer-dist --no-progress \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache storage/app/backups \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

# Bookworm's default client is PostgreSQL 15. The server is 16, and pg_dump refuses a newer server.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /usr/share/keyrings/postgresql.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/postgresql.gpg] http://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client-16 \
    && ln -sf /usr/lib/postgresql/16/bin/pg_dump /usr/local/bin/pg_dump \
    && ln -sf /usr/lib/postgresql/16/bin/pg_restore /usr/local/bin/pg_restore \
    && rm -rf /var/lib/apt/lists/*

EXPOSE 80
# The official image entrypoint only forwards the command. Compose overrides this
# CMD for the scheduler and the queue worker, which do not start nginx.
CMD ["web-serve"]
