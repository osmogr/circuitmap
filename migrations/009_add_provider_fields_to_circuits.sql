ALTER TABLE circuits ADD COLUMN provider_id INTEGER REFERENCES circuit_providers(id);
ALTER TABLE circuits ADD COLUMN provider_circuit_id TEXT;
ALTER TABLE circuits ADD COLUMN order_number TEXT;
ALTER TABLE circuits ADD COLUMN redundant INTEGER NOT NULL DEFAULT 0 CHECK (redundant IN (0, 1));

CREATE INDEX IF NOT EXISTS idx_circuits_provider_id ON circuits(provider_id);
