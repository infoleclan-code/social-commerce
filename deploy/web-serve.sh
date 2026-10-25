#!/bin/sh
set -eu

php artisan migrate --force
php-fpm -D
exec nginx -g 'daemon off;'
