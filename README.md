# CircuitMap

Self-contained, Dockerized web app for uploading, viewing, and editing
KML/KMZ fiber circuit routes on a map. Runs entirely via `docker compose up`
on an internal Docker host, behind an optional external reverse proxy.

## Stack

- Backend: PHP 8.2, Slim Framework 4 (PSR-7/15), plain PHP templates.
- Web server: nginx + PHP-FPM in a single container (supervisord).
- Database: SQLite (WAL mode) on a named Docker volume.
- Frontend: Leaflet (map display) + Leaflet-Geoman (map-based geometry
  editor), vendored locally, no CDN dependency.
- File storage: raw KML files on a named Docker volume, keyed by a
  server-generated UUID (never a user-supplied filename).

See the project plan (if present in your checkout) for the full
architecture rationale. This document covers day-to-day setup and
operations.

## Quick start

1. Copy the example environment file and fill in real values:

   ```
   cp .env.example .env
   ```

   At minimum, set `INITIAL_ADMIN_USERNAME` and `INITIAL_ADMIN_PASSWORD`
   (12+ characters). These are only consumed once, on first boot, to
   create the initial admin account if the `users` table is empty.

2. Start the stack:

   ```
   docker compose up --build -d
   ```

3. Visit `http://<host>:<APP_PORT>/` (default port 8080). Log in with the
   initial admin account and change the password by creating a new admin
   user and deactivating the bootstrap one, or by re-running the bootstrap
   against a fresh database.

4. Check `http://<host>:<APP_PORT>/healthz` for a plain `OK` if you need a
   liveness check independent of the UI.

No other host-level setup is required. Migrations run automatically on
container start (`docker/app/entrypoint.sh`), and are idempotent (a
`schema_migrations` table tracks what has already been applied).

## Configuration reference (`.env`)

| Variable | Default | Purpose |
|---|---|---|
| `APP_PORT` | `8080` | Host port mapped to the container's nginx. |
| `APP_DEBUG` | `false` | Show detailed error responses. Keep `false` outside local dev. |
| `REQUIRE_AUTH_FOR_VIEW` | `false` | If `true`, viewing the map/API also requires login, not just upload/edit/delete. |
| `COOKIE_SECURE` | `true` | Marks the session cookie `Secure` (HTTPS only). Set `false` only for plain-HTTP local testing. |
| `INITIAL_ADMIN_USERNAME` / `INITIAL_ADMIN_PASSWORD` | (required) | Bootstrap admin account, created once if `users` is empty. |
| `MAX_UPLOAD_BYTES` | `10485760` (10 MB) | Application-level upload size cap, in addition to nginx/php.ini limits baked into the image. |
| `INSTANCE_IMPORT_MAX_BYTES` | `1073741824` (1 GiB) | Maximum total uncompressed size of a full-instance import archive (Admin → Instance Transfer). |
| `BASE_PATH` | (empty, serves from `/`) | Mount the app under a sub-path, e.g. `/circuitmap`, instead of the domain root. Must start with `/` and have no trailing slash. |

The app is designed to sit behind an existing reverse proxy (nginx/Caddy)
that terminates TLS; it does not terminate TLS itself. It trusts
`X-Forwarded-For` for logging/rate-limiting client IPs, which assumes the
proxy in front of it is trusted infrastructure you control - do not expose
the app container's port directly to an untrusted network without a proxy
in front of it.

If you set `BASE_PATH`, the proxy must forward the full path under that
prefix through to this container unchanged (no strip-prefix rewrite) -
e.g. a request to `https://host/circuitmap/login` should reach this
container as `/circuitmap/login`, not `/login`.

## Authentication and roles

- Three roles:
  - `readonly` (default for new users): can view the map and circuits, but
    cannot create, edit, or delete anything.
  - `editor`: can upload/create circuits, and edit/delete/set status on
    circuits they uploaded; can also add and update circuit providers and
    sites/locations, but cannot deactivate them.
  - `admin`: can do all of that on every circuit (not just ones they
    uploaded), deactivate providers and sites/locations, manage users at
    `/admin/users`, and review `/admin/audit-log`.
- Read-only map viewing is public by default; set `REQUIRE_AUTH_FOR_VIEW=true`
  to gate it too.
- Sessions are stored in the database (not container-local disk), so they
  survive `docker compose restart` / container recreation, as long as the
  `db-data` volume persists.
- An admin cannot deactivate their own account or remove their own admin
  role through the UI, to avoid locking every admin out. If you do end up
  with zero active admins, the only recovery path is a fresh database (see
  Backup/restore) with the bootstrap admin flow, or direct SQLite access to
  fix a row.

## Adding an external status data source adapter

Real-time status linking is intentionally **not implemented** in this
build - only the groundwork is in place, per the original project
requirements. Today, status is only ever set manually through the edit
page's Status dropdown, which calls `POST /circuits/{uuid}/status` and
writes `status`, `status_source = "manual"`, and `status_updated_at`
directly onto the `circuits` row.

The extension point is `CircuitMap\Services\Status\StatusProviderInterface`:

```php
interface StatusProviderInterface
{
    public function getStatus(string $circuitUuid): StatusResult;
}
```

`StatusResult` is a small value object: `status` (`unknown|up|degraded|down`),
`source` (a label identifying where the value came from), and `updatedAt`
(ISO-8601 UTC string or null).

`CircuitMap\Services\Status\ManualStatusProvider` is the only
implementation shipped today; it just reads whatever is already stored on
the circuit row. It is wired up in `backend/src/App.php`
(`buildServices()`), where a `$statusProvider` variable is constructed and
passed into `StatusController`. `GET /api/circuits/{uuid}/status` calls
`$statusProvider->getStatus()` and is the one place that reads through the
interface today.

To add a real integration:

1. Implement `StatusProviderInterface` in a new class under
   `backend/src/Services/Status/` (e.g. `AcmeNmsStatusProvider`).
2. Decide how it gets triggered:
   - **Polling**: add a scheduler (cron inside the container, or an
     external job hitting a new internal-only endpoint) that periodically
     calls your provider for each circuit and writes the result through
     `CircuitRepository::updateStatus($id, $status, $sourceLabel)`, the
     same method the manual endpoint already uses.
   - **Webhook**: add a new authenticated route (a shared secret from an
     env var, not a session cookie, since it's called by another system)
     that receives a push notification from the external system, maps it
     to a circuit (e.g. by a `status_source` identifier or a new external-ID
     column), and calls `updateStatus()` the same way.
3. Any credentials/API keys the adapter needs belong in environment
   variables (add them to `.env.example` and `docker-compose.yml`, not in
   the database) - see "Secrets" below.
4. Swap the `$statusProvider = new ManualStatusProvider($circuits);` line
   in `App.php` for your new adapter (or make it conditional on an env
   var if you want both available). No other code needs to change: the
   map API, the color-coding, and the UI all already read from whatever
   is stored on the circuit row, regardless of what wrote it there.

The map already color-codes circuits by status
(`CircuitMap\Support\StatusColor`: gray/green/amber/red for
unknown/up/degraded/down) using whatever is in the database, so a real
adapter "just works" visually the moment it starts calling `updateStatus()`.

## Secrets

- The initial admin password lives only in `.env` (excluded from version
  control via `.gitignore`) and is consumed once at bootstrap; it is not
  re-read afterward.
- User passwords are stored as `password_hash()` output (bcrypt/argon2id
  depending on PHP's default), never plaintext.
- Any future external status adapter's API keys should go in `.env` /
  `docker-compose.yml` environment variables, following the same pattern
  as the rest of the app's configuration, and should never be written into
  the `circuits` table or any other database row.

## Security notes (summary)

- **XXE**: KML uploads are parsed with a hardened `DOMDocument` configuration
  (`LIBXML_NONET`, never `LIBXML_NOENT`/`LIBXML_DTDLOAD`/`LIBXML_PARSEHUGE`),
  and any file containing a `<!DOCTYPE` is rejected outright regardless of
  its content. See `backend/src/Services/Kml/KmlParser.php` for the full
  rationale in comments.
- **Stored XSS**: placemark `<description>` HTML/CDATA is passed through
  HTMLPurifier (allow-list) before storage, both on upload and on edit; the
  sanitized value is what's written to disk, not just what's echoed.
- **Path traversal**: every circuit's files live under a directory named
  from a server-generated UUID; `FileStorageService` validates that UUID
  against a strict regex before any path concatenation. See
  `backend/src/Support/Uuid.php` and
  `backend/src/Services/Storage/FileStorageService.php`.
- **KMZ (zip) handling**: entries are checked for zip-slip (path traversal
  inside the archive) and decompression-bomb ratios before extraction. See
  `backend/src/Services/Kml/KmzExtractor.php`.
- **CSRF**: a per-session token is required (header `X-CSRF-Token` for AJAX,
  hidden field for forms) on every state-changing request.
- **Rate limiting**: SQLite-backed fixed-window limiter on login, upload,
  and edit/status endpoints. This is adequate at the "dozens to low
  hundreds of circuits, small internal team" scale this app targets; if
  traffic grows well beyond that, replace `RateLimiterService`'s backing
  store with Redis rather than assuming SQLite scales indefinitely for
  this purpose.
- **Dependency hygiene**: `backend/composer.lock` pins exact versions;
  commit it, and re-run `composer update` deliberately (not automatically)
  to review changes before pulling in new dependency versions.

## Testing

Run the PHPUnit suite via the Docker Compose `test` profile - no host PHP
toolchain required:

```
docker compose --profile test run --rm test
```

This builds a separate image target (`docker/app/Dockerfile`, `test`
stage) with dev dependencies and the `tests/` directory, and runs against
a throwaway SQLite file, not your real data volume.

Coverage includes: hardened KML parsing (XXE/DOCTYPE rejection, malformed
XML, oversized/deeply-nested input), structural KML validation, HTML
sanitization of descriptions, KMZ zip-slip/decompression-bomb rejection,
path-traversal-safe file storage, rate limiting, and integration tests for
the upload, edit (including version archiving and revert), delete, and
manual status-setting flows.

## Backup and restore

All persistent state lives on two named Docker volumes:

- `db-data` - the SQLite database (`circuitmap.sqlite`), including users,
  circuit metadata, version history rows, audit log, and sessions.
- `kml-data` - the raw KML files, one directory per circuit UUID, each with
  a `current.kml` and a `versions/` subdirectory of prior snapshots.

**Backup** (safe to run while the app is up - SQLite's own `.backup`
command handles the live-database case correctly):

```
# Database: use SQLite's "VACUUM INTO" for a consistent online backup
# (the sqlite3 CLI is not installed in the runtime image, only the PHP
# pdo_sqlite extension is, so this runs through PHP instead).
docker compose exec app php -r \
  '(new PDO("sqlite:/var/lib/circuitmap/db/circuitmap.sqlite"))->exec("VACUUM INTO \x27/tmp/backup.sqlite\x27");'
docker compose cp app:/tmp/backup.sqlite ./circuitmap-db-backup-$(date +%Y%m%d).sqlite

# KML files: safe to copy directly, they are write-once-then-replace, not
# append-in-place.
docker run --rm -v circuitmap_kml-data:/data -v "$(pwd)":/backup alpine \
  tar czf /backup/circuitmap-kml-backup-$(date +%Y%m%d).tar.gz -C /data .
```

**Restore:**

```
docker compose down
docker run --rm -v circuitmap_db-data:/data -v "$(pwd)":/backup alpine \
  sh -c "cp /backup/circuitmap-db-backup-YYYYMMDD.sqlite /data/circuitmap.sqlite"
docker run --rm -v circuitmap_kml-data:/data -v "$(pwd)":/backup alpine \
  sh -c "cd /data && tar xzf /backup/circuitmap-kml-backup-YYYYMMDD.tar.gz"
docker compose up -d
```

Schedule the backup commands (cron, systemd timer, or your existing backup
tooling) on the Docker host; this project does not run its own backup
scheduler.

## Instance transfer (full export/import)

Admins can move everything in one CircuitMap to a new deployment without
touching Docker volumes, from **Admin → Instance Transfer**
(`/admin/instance`):

- **Export** downloads a single ZIP containing every persistent table
  (users including password hashes, circuits, version history, providers,
  locations, audit log) plus the complete per-circuit KML tree. Treat the
  file like a database backup - it contains credentials.
- **Import** uploads that ZIP into a **fresh, empty** instance (no
  circuits/providers/locations and no users beyond the bootstrap admin)
  and reproduces the source exactly, preserving row ids, circuit UUIDs and
  timestamps. The restore is all-or-nothing: any validation or write
  failure rolls everything back. Both instances must be on the same
  CircuitMap version (the archive records the applied migrations and the
  import refuses on a mismatch).
- Imported accounts **replace** the bootstrap admin. If the archive has an
  active admin with your username you stay logged in as that account;
  otherwise you are logged out and must sign in with an admin account from
  the imported data.

The report page (`/circuits/report`) also has an **Export Circuits.csv**
link that downloads all circuit fields (plus provider and A/Z location
names) as CSV - that one is a data report, not a backup.

## Known assumptions and limitations

- SQLite is used instead of MySQL/MariaDB, sized for "dozens to low
  hundreds of circuits" with a small number of internal editors; the data
  layer uses a repository pattern specifically so this can be swapped
  later if usage grows beyond that.
- Map tiles (OpenStreetMap) are loaded over the network at runtime; this is
  the one external dependency that could not reasonably be vendored.
  Everything else (Leaflet, Leaflet-Geoman, app JS/CSS) is served locally.
- KMZ support assumes the common case of one `doc.kml` per archive; ground
  overlays and embedded icons/images are not rendered.
- Circuit-level metadata (name/description/tags) is one set of fields per
  circuit; individual placemarks within a circuit's KML can have their own
  name/description, editable per-placemark in the map editor, but the
  circuit list only shows the circuit-level name.
