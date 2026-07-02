CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER REFERENCES users(id),
    data BLOB NOT NULL DEFAULT '',
    last_activity TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_last_activity ON sessions(last_activity);
