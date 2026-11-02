<?php
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $pdo = new PDO('sqlite:' . DATA_DIR . '/replay.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $db): void {
    $db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        google_sub TEXT UNIQUE,
        email TEXT UNIQUE NOT NULL,
        name TEXT, picture TEXT,
        role TEXT NOT NULL DEFAULT 'user',
        disabled INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        last_login INTEGER
    );
    CREATE TABLE IF NOT EXISTS user_settings (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        retention_hours INTEGER NOT NULL DEFAULT 24,
        auto_clean INTEGER NOT NULL DEFAULT 1,
        ml_enabled INTEGER NOT NULL DEFAULT 1,
        ml_threshold REAL NOT NULL DEFAULT 0.5,
        last_cleanup_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS interactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        platform TEXT NOT NULL,
        url TEXT NOT NULL,
        url_key TEXT NOT NULL,
        title TEXT,
        content_type TEXT NOT NULL DEFAULT 'other',
        first_opened_at INTEGER NOT NULL,
        last_opened_at INTEGER NOT NULL,
        open_count INTEGER NOT NULL DEFAULT 1,
        dwell_seconds INTEGER NOT NULL DEFAULT 0,
        pinned INTEGER NOT NULL DEFAULT 0,
        sampled INTEGER NOT NULL DEFAULT 0,
        reviewed_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_int_user ON interactions(user_id, last_opened_at DESC);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_int_key ON interactions(user_id, url_key);
    CREATE TABLE IF NOT EXISTS training_samples (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        features TEXT NOT NULL,
        label INTEGER NOT NULL,
        created_at INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_ts_user ON training_samples(user_id, id DESC);
    CREATE TABLE IF NOT EXISTS ml_models (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        weights TEXT NOT NULL,
        samples INTEGER NOT NULL,
        accuracy REAL NOT NULL,
        trained_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS cleanup_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        run_at INTEGER NOT NULL,
        deleted INTEGER NOT NULL,
        kept_by_ml INTEGER NOT NULL,
        mode TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS app_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    INSERT OR IGNORE INTO app_settings VALUES ('default_retention_hours','24');
    INSERT OR IGNORE INTO app_settings VALUES ('min_retention_hours','1');
    INSERT OR IGNORE INTO app_settings VALUES ('max_retention_hours','720');
    ");
}

function app_setting(string $key): int {
    $s = db()->prepare('SELECT value FROM app_settings WHERE key=?');
    $s->execute([$key]);
    return (int)$s->fetchColumn();
}

function retention_bounds(): array {
    return ['min' => max(1, app_setting('min_retention_hours')), 'max' => max(1, app_setting('max_retention_hours')),
            'default' => app_setting('default_retention_hours')];
}
