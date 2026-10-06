#!/bin/sh
set -e

# Run every console command as www-data (the user php-fpm runs as), via su-exec.
# If these ran as root — the entrypoint's own user — they would create root-owned
# files under var/log and var/cache that php-fpm cannot write, which makes any
# request that logs (e.g. the checkout shipping-rates / create-payment-intent
# endpoints) fail with a 500. Defensively re-own var first, in case an older
# image or a restart left root-owned files behind.
chown -R www-data:www-data /var/www/html/var || true
CONSOLE="su-exec www-data php bin/console"

echo "==> Warming up cache..."
$CONSOLE cache:warmup --env=prod --no-debug

# Migrations run on boot by default, as before. Set RUN_MIGRATIONS=0 to skip
# them — useful when a release carries a destructive migration that should be
# applied deliberately, with a backup taken first, rather than by whichever
# container happens to start next. Several containers booting at once would
# otherwise race on the same schema change.
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    echo "==> Running migrations..."
    $CONSOLE doctrine:migrations:migrate --no-interaction --env=prod
else
    echo "==> Skipping migrations (RUN_MIGRATIONS=0)"
fi

# Warm the shipping-rate fallback snapshots on startup, as the roadmap requires.
# Backgrounded on purpose: rating every probe against Shippo can be slow and the
# carrier may be unreachable, and neither must delay the container coming up. It
# stores only real rates and never overwrites a good snapshot with an empty one,
# so a failed run at boot is harmless. Runs as www-data (above) so its shipping-log
# writes stay www-data-owned. Set REFRESH_SHIPPING_SNAPSHOTS=0 to skip. The
# recurring refresh is a separate hourly cron entry (see ARCHITECTURE.md §5),
# which must also run as www-data (docker exec -u www-data).
if [ "${REFRESH_SHIPPING_SNAPSHOTS:-1}" = "1" ]; then
    echo "==> Refreshing shipping snapshots in the background..."
    $CONSOLE app:shipping:refresh-snapshots --env=prod --no-interaction >/dev/null 2>&1 &
fi

# Supervisord becomes PID 1 and runs php-fpm + nginx in the foreground, so both
# are watched and the container exits if either one dies for good.
echo "==> Starting php-fpm and nginx under supervisord..."
exec supervisord -c /etc/supervisord.conf
