ALTER TABLE circuits ADD COLUMN cacti_host_id INTEGER;
ALTER TABLE circuits ADD COLUMN cacti_local_data_id INTEGER;
ALTER TABLE circuits ADD COLUMN capacity_bps INTEGER;
ALTER TABLE circuits ADD COLUMN usage_in_bps INTEGER;
ALTER TABLE circuits ADD COLUMN usage_out_bps INTEGER;
ALTER TABLE circuits ADD COLUMN usage_updated_at TEXT;

CREATE INDEX IF NOT EXISTS idx_circuits_cacti_host_id ON circuits(cacti_host_id);
