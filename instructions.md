# Prompt for Claude CLI: KML Fiber Circuit Viewer (Dockerized Web App)

## Project Goal

Build a self-contained, Dockerized web application for uploading, storing, viewing,
and editing KML files that represent fiber circuit routes. Circuits can optionally
be linked to an external data source to pull real-time status (up/down/degraded).
This runs on an internal network on a Docker host I control.

## Core Features

1. **KML Upload**
   - Users upload `.kml` (and `.kmz`, if reasonable) files via a web form.
   - Files are validated, sanitized, and stored server-side (filesystem or object
     storage — your call, but be explicit about the choice and layout).
   - Each uploaded circuit gets a name, optional description/tags, upload
     timestamp, and uploader identity (if auth is enabled).

2. **Map Display**
   - Uploaded circuits render on an interactive map (Leaflet or OpenLayers).
   - Support toggling visibility of individual circuits, viewing metadata on
     click/hover, and basic styling per circuit (color/line style).
   - Should handle a reasonable number of circuits (dozens to low hundreds)
     without falling over.

3. **KML Editing**
   - Users can edit an existing uploaded KML: reposition points/paths, edit
     placemark names/descriptions, and save changes back.
   - Editing should not require a full re-upload; provide either a map-based
     editor (e.g., Leaflet.draw / geoman) or a structured form/JSON editor,
     your recommendation is fine — just justify the tradeoff briefly in your
     plan before building.
   - Keep version history or at least a single-level "previous version" backup
     on edit, so a bad edit is recoverable.

4. **Real-Time Status Linking (groundwork only, not wired up yet)**
   - This integration is intentionally deferred. Do NOT implement an actual
     polling loop, webhook receiver, or vendor-specific API call in this pass.
   - Instead, lay the groundwork so it's easy to plug in later:
     - Data model: each circuit has optional fields for `status` (e.g.
       unknown/up/degraded/down), `status_source` (identifier/label for
       where it would come from), and `status_updated_at`.
     - A defined but unimplemented adapter interface/class stub (e.g.
       `StatusProviderInterface` with a `getStatus($circuitId)` method) that
       future adapters would implement. One trivial "manual/static" provider
       is fine as a working example (lets a user manually set a circuit's
       status via the UI), but no live external calls yet.
     - Map UI should already support color-coding circuits by status
       (green/yellow/red/gray-for-unknown) using whatever is in the database,
       even though nothing populates it automatically yet.
   - Note in the README that this is a stubbed integration point and outline
     what a future adapter implementation (polling or webhook-based) would
     need to plug in.

## Technical Requirements

- **Fully containerized** via Docker Compose — should come up with `docker compose up`
  and require no manual host-level setup beyond providing a `.env` file.
- Stack:
  - Backend: PHP (plain PHP or a lightweight framework such as Slim — your
    call, but avoid heavy frameworks unless there's a good reason). Prefer
    broadly compatible syntax rather than bleeding-edge PHP-only features
    unless there's a clear benefit.
  - Web server: PHP-FPM + nginx (or Apache with mod_php, your call) in the
    container.
  - Frontend: Server-rendered templates or a lightweight JS frontend using
    Leaflet for mapping — avoid unnecessary framework bloat.
  - Storage: SQLite or MySQL/MariaDB for metadata (your call based on
    expected scale); raw KML files stored on a Docker volume, not in the
    database.
  - Reverse proxy: assume this may sit behind an existing reverse proxy
    (nginx/Caddy) — don't bake in TLS termination, but make ports configurable.
- Persist all data (uploaded files + database) on Docker volumes, not inside
  the container filesystem, so `docker compose down` doesn't lose data.
- Provide a `.env.example` for all required configuration (ports, secrets,
  external data source URLs, etc.) — never commit real secrets.

## Security & Data Handling Requirements (non-negotiable)

- **XXE prevention**: KML is XML. Use a hardened parsing configuration that
  disables external entity resolution and DTD loading (e.g., in PHP, ensure
  `libxml_disable_entity_loader` is set where applicable for the PHP version
  in use, load XML with `LIBXML_NONET` and without `LIBXML_NOENT`, and avoid
  `simplexml_load_string`/`DOMDocument::loadXML` defaults that permit entity
  expansion). Verify the exact safe configuration for whatever PHP version
  ends up in the container, since defaults have changed across PHP versions.
  Do not use a naive/default XML parser call on user-uploaded KML under any
  circumstances.
- **Upload validation**: enforce file size limits, validate file extension AND
  actual content/MIME type, reject anything that doesn't parse as valid KML/KMZ
  structure, and strip/reject embedded scripts or unexpected elements in
  placemark descriptions (KML descriptions can contain HTML/CDATA — sanitize
  this before rendering to prevent stored XSS).
- **Path traversal protection**: never trust user-supplied filenames for
  server-side storage paths; generate safe internal filenames/IDs.
- **Authentication**: include at least basic authentication (session-based
  login) gating upload/edit/delete actions. Read-only map viewing can be
  open or gated depending on how I configure it — make this configurable.
- **Authorization**: if multi-user, ensure users can't edit/delete circuits
  they don't own unless an admin role is granted.
- **CSRF protection** on all state-changing form submissions.
- **Secrets management**: API keys/credentials for external data sources
  must be stored in environment variables or a secrets file excluded from
  version control — never in the database in plaintext if avoidable, or at
  minimum document the tradeoff clearly if plaintext storage is used.
- **Rate limiting** on upload and API endpoints to prevent abuse.
- **Dependency hygiene**: pin dependency versions, avoid known-vulnerable
  packages, and note this is something to keep patched over time.
- **Logging**: log uploads, edits, and auth events (who/when/what) without
  logging sensitive data (credentials, full file contents).
- **Backups**: document how to back up the volumes (KML files + database)
  since this is operational infrastructure data I can't afford to lose.

## Deliverables

1. A short written plan/architecture summary before writing code — stack
   choices, data model, directory layout, and how the external status
   adapter will be structured. Wait for my confirmation on this before
   full implementation if any major assumption is unclear.
2. Full project source, organized cleanly (backend, frontend, docker
   configs, migrations if applicable).
3. `docker-compose.yml`, `Dockerfile`(s), and `.env.example`.
4. A `README.md` covering: setup, configuration, how to add an external
   status data source adapter, and backup/restore steps.
5. Basic tests for KML parsing/validation and upload handling, at minimum.

## Constraints

- Plain ASCII only in code comments and strings — no em dashes or
  typographic/smart-quote characters.
- Favor a simple, working iteration first over a feature-complete build.
  Get upload -> store -> display working end-to-end before layering on
  editing and real-time status linking.
- Call out any assumption you make explicitly rather than silently
  guessing on ambiguous requirements.
