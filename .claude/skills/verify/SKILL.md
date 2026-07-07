---
name: verify
description: Launch CircuitMap with live sources for runtime verification of changes (throwaway container, isolated DB/storage, curl-driven flows).
---

# Verifying CircuitMap changes at runtime

No host PHP. The unit/integration suite runs in Docker:

```bash
docker compose --profile test run --rm \
  -v "$PWD/backend/src:/var/www/app/src:ro" \
  -v "$PWD/backend/templates:/var/www/app/templates:ro" \
  -v "$PWD/tests:/var/www/tests:ro" \
  test php /var/www/app/vendor/bin/phpunit -c /var/www/tests/phpunit.xml --do-not-cache-result
```

(The test image bakes sources in at build time; the mounts override them so
no rebuild is needed. New classes resolve via PSR-4 without a composer dump.)

For **runtime** verification, do NOT restart the production `circuitmap-app-1`
container (it serves a live tunnel). Start a throwaway container from the
existing runtime image with live sources mounted and isolated state:

```bash
docker run -d --name circuitmap-verify \
  -p 127.0.0.1:8123:8080 \
  -e APP_DEBUG=true \
  -e DB_PATH=/tmp/verify.sqlite \
  -e MIGRATIONS_PATH=/var/www/migrations \
  -e KML_STORAGE_PATH=/tmp/verify-kml \
  -e COOKIE_SECURE=false \
  -e INITIAL_ADMIN_USERNAME=verifyadmin \
  -e INITIAL_ADMIN_PASSWORD='verify-password-12345' \
  -v "$PWD/backend/src:/var/www/app/src:ro" \
  -v "$PWD/backend/templates:/var/www/app/templates:ro" \
  -v "$PWD/frontend:/var/www/frontend:ro" \
  circuitmap-app:local
# entrypoint runs migrations + bootstraps the admin; wait ~3s, then:
curl -s http://127.0.0.1:8123/healthz   # -> OK
```

Driving flows with curl (session + CSRF):

```bash
JAR=cookies.txt; BASE=http://127.0.0.1:8123
CSRF=$(curl -s -c $JAR $BASE/login | grep -o 'name="csrf_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
curl -s -b $JAR -c $JAR -d "username=verifyadmin&password=verify-password-12345&csrf_token=$CSRF" $BASE/login
# uploads are multipart: -F "csrf_token=..." -F "name=..." -F "kml_file=@tests/fixtures/valid_simple.kml" $BASE/upload
# CSRF token is stable per session; reuse it for subsequent POSTs.
```

Useful checks: `GET /api/circuits` (JSON list), `GET /api/circuits/{uuid}/geojson`
(proves stored KML parses/converts), `docker exec circuitmap-verify ls /tmp/verify-kml/...`
for storage state.

Tear down: `docker rm -f circuitmap-verify`.

Gotchas:
- Host ports 8080/8099 are taken by other services; bind 127.0.0.1:8123.
- `COOKIE_SECURE=false` is required or the session cookie is dropped over HTTP.
- If `circuitmap-app:local` is stale (e.g. composer.json changed), rebuild with
  `docker compose build app` first; mounted src/templates cover PHP-only changes.
