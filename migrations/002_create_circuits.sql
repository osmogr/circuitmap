CREATE TABLE IF NOT EXISTS circuits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT,
    tags TEXT,
    owner_id INTEGER NOT NULL REFERENCES users(id),
    current_file_path TEXT NOT NULL,
    current_version INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'unknown' CHECK (status IN ('unknown', 'up', 'degraded', 'down')),
    status_source TEXT,
    status_updated_at TEXT,
    color TEXT,
    uploaded_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_circuits_owner_id ON circuits(owner_id);
CREATE INDEX IF NOT EXISTS idx_circuits_deleted_at ON circuits(deleted_at);
