CREATE TABLE IF NOT EXISTS circuit_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    circuit_id INTEGER NOT NULL REFERENCES circuits(id) ON DELETE CASCADE,
    version_number INTEGER NOT NULL,
    file_path TEXT NOT NULL,
    name_snapshot TEXT,
    description_snapshot TEXT,
    edited_by INTEGER REFERENCES users(id),
    created_at TEXT NOT NULL,
    UNIQUE (circuit_id, version_number)
);

CREATE INDEX IF NOT EXISTS idx_circuit_versions_circuit_id ON circuit_versions(circuit_id);
