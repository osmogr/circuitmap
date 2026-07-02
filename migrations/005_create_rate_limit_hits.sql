CREATE TABLE IF NOT EXISTS rate_limit_hits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bucket_key TEXT NOT NULL,
    window_start TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 1,
    UNIQUE (bucket_key, window_start)
);
