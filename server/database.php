<?php
function open_database(string $path): PDO
{
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    // Serialize schema upgrades as well as writes across concurrent requests.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec('CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, checked INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0)');
        $columns = $db->query('PRAGMA table_info(items)')->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array('request_id', array_column($columns, 'name'), true)) {
            $db->exec('ALTER TABLE items ADD COLUMN request_id TEXT');
        }
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_items_request_id ON items(request_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_items_sort ON items(checked, position, id)');
        $db->exec('CREATE TABLE IF NOT EXISTS list_state (id INTEGER PRIMARY KEY CHECK(id = 1), revision INTEGER NOT NULL)');
        $db->exec('INSERT OR IGNORE INTO list_state VALUES (1, 0)');
        $db->exec('CREATE TABLE IF NOT EXISTS operations (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, result TEXT NOT NULL)');
        $db->exec('COMMIT');
    } catch (Throwable $error) {
        $db->exec('ROLLBACK');
        throw $error;
    }
    return $db;
}
