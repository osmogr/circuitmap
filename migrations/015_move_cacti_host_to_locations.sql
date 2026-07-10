ALTER TABLE locations ADD COLUMN cacti_host_id INTEGER;
ALTER TABLE locations ADD COLUMN status TEXT NOT NULL DEFAULT 'unknown'
    CHECK (status IN ('unknown', 'up', 'degraded', 'down'));
ALTER TABLE locations ADD COLUMN status_updated_at TEXT;

CREATE INDEX IF NOT EXISTS idx_locations_cacti_host_id ON locations(cacti_host_id);

-- Circuit status is manual-only from here on: device up/down now belongs to
-- the location. Clear old device mappings (device IDs are re-entered per
-- site) and reset cacti-sourced circuit statuses, which nothing will ever
-- refresh again.
UPDATE circuits SET cacti_host_id = NULL;
UPDATE circuits SET status = 'unknown', status_source = NULL, status_updated_at = NULL
WHERE status_source = 'cacti';
