#!/bin/sh
set -e

echo "==> Warming up cache..."
php bin/console cache:warmup --env=prod --no-debug

# Migrations run on boot by default, as before. Set RUN_MIGRATIONS=0 to skip
# them — useful when a release carries a destructive migration that should be
# applied deliberately, with a backup taken first, rather than by whichever
# container happens to start next. Several containers booting at once would
# otherwise race on the same schema change.
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    echo "==> Running migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --env=prod
else
    echo "==> Skipping migrations (RUN_MIGRATIONS=0)"
fi

# Supervisord becomes PID 1 and runs php-fpm + nginx in the foreground, so both
# are watched and the container exits if either one dies for good.
echo "==> Starting php-fpm and nginx under supervisord..."
exec supervisord -c /etc/supervisord.conf
