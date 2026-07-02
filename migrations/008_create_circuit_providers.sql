CREATE TABLE IF NOT EXISTS circuit_providers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    tech_support_number TEXT,
    account_id TEXT,
    local_rep_contact TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_circuit_providers_is_active ON circuit_providers(is_active);
