#!/bin/sh
set -e

# BASE_PATH must be resolved into the nginx config at container start (not
# build time) since it's a per-deployment env var; envsubst is scoped to
# just this one variable so it doesn't also mangle nginx's own $uri,
# $document_root, $fastcgi_path_info, etc., which look like shell vars but
# aren't.
export BASE_PATH="${BASE_PATH:-}"
envsubst '${BASE_PATH}' < /etc/nginx/templates/nginx.conf.template > /etc/nginx/sites-enabled/default

echo "Running database migrations..."
su -s /bin/sh www-data -c "php /var/www/app/bin/migrate.php"

echo "Checking initial admin account..."
su -s /bin/sh www-data -c "php /var/www/app/bin/bootstrap_admin.php"

echo "Starting CircuitMap..."
exec "$@"
