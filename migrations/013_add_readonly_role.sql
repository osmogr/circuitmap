-- SQLite can't ALTER a CHECK constraint in place, so rebuild the table.
-- FK actions don't fire on DROP/RENAME (only on DELETE/UPDATE), so this is
-- safe to run inside the migration runner's existing transaction without
-- touching PRAGMA foreign_keys.
CREATE TABLE users_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('readonly', 'editor', 'admin')),
    display_name TEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    last_login_at TEXT
);

INSERT INTO users_new (id, username, password_hash, role, display_name, is_active, created_at, last_login_at)
SELECT id, username, password_hash, role, display_name, is_active, created_at, last_login_at FROM users;

DROP TABLE users;

ALTER TABLE users_new RENAME TO users;
