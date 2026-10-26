#!/bin/sh
set -eu

php artisan migrate --force
php artisan fx:refresh || true
php-fpm -D
exec nginx -g 'daemon off;'
