#!/bin/sh
set -e

echo "Running database migrations..."
su -s /bin/sh www-data -c "php /var/www/app/bin/migrate.php"

echo "Checking initial admin account..."
su -s /bin/sh www-data -c "php /var/www/app/bin/bootstrap_admin.php"

echo "Starting CircuitMap..."
exec "$@"
