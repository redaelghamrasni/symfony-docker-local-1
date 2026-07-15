#!/bin/sh
set -e

echo "==> Warming up cache..."
php bin/console cache:warmup --env=prod --no-debug

echo "==> Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

echo "==> Starting PHP-FPM..."
php-fpm -D

echo "==> Starting Nginx..."
exec nginx -g "daemon off;"
