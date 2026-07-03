ALTER TABLE circuits ADD COLUMN a_location_id INTEGER REFERENCES locations(id);
ALTER TABLE circuits ADD COLUMN z_location_id INTEGER REFERENCES locations(id);

CREATE INDEX IF NOT EXISTS idx_circuits_a_location_id ON circuits(a_location_id);
CREATE INDEX IF NOT EXISTS idx_circuits_z_location_id ON circuits(z_location_id);
