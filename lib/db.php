<?php
declare(strict_types=1);

/**
 * SQLite connection for encounterManager.
 * The database file lives in ../data/ (blocked from the web by .htaccess).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = __DIR__ . '/../data/encounters.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS encounters (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            round      INTEGER NOT NULL DEFAULT 0,
            active_id  INTEGER NULL,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS combatants (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            encounter_id INTEGER NOT NULL REFERENCES encounters(id) ON DELETE CASCADE,
            name         TEXT    NOT NULL,
            kind         TEXT    NOT NULL DEFAULT 'monster',
            init_mod     INTEGER NOT NULL DEFAULT 0,
            initiative   INTEGER NULL,
            ac           INTEGER NULL,
            hp_max       INTEGER NOT NULL DEFAULT 0,
            hp           INTEGER NOT NULL DEFAULT 0,
            temp_hp      INTEGER NOT NULL DEFAULT 0,
            conditions   TEXT    NOT NULL DEFAULT '[]',
            notes        TEXT    NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS idx_combatants_enc ON combatants(encounter_id);
    SQL);

    // Migrations for databases created by earlier versions.
    $cols = array_column($pdo->query('PRAGMA table_info(encounters)')->fetchAll(), 'name');
    if (!in_array('campaign', $cols, true)) {
        $pdo->exec('ALTER TABLE encounters ADD COLUMN campaign TEXT NULL');
    }
    // paused = 1 keeps round and whose turn it is, but blocks Next / Prev until resumed.
    if (!in_array('paused', $cols, true)) {
        $pdo->exec('ALTER TABLE encounters ADD COLUMN paused INTEGER NOT NULL DEFAULT 0');
    }
    // in_combat = 0 means "waiting to join" (a later wave): not in the turn order yet.
    $cols = array_column($pdo->query('PRAGMA table_info(combatants)')->fetchAll(), 'name');
    if (!in_array('in_combat', $cols, true)) {
        $pdo->exec('ALTER TABLE combatants ADD COLUMN in_combat INTEGER NOT NULL DEFAULT 1');
    }
    // dead = 1 removes them from the turn order into the "Fallen" list.
    if (!in_array('dead', $cols, true)) {
        $pdo->exec('ALTER TABLE combatants ADD COLUMN dead INTEGER NOT NULL DEFAULT 0');
    }
    // level (PCs) and cr (monsters/NPCs) drive the encounter difficulty meter.
    if (!in_array('level', $cols, true)) {
        $pdo->exec('ALTER TABLE combatants ADD COLUMN level INTEGER NULL');
    }
    if (!in_array('cr', $cols, true)) {
        $pdo->exec('ALTER TABLE combatants ADD COLUMN cr REAL NULL');
        // Monsters added from Notion search carry "CR 1/2 · …" in their notes: recover it.
        $st = $pdo->prepare('UPDATE combatants SET cr = ? WHERE id = ?');
        foreach ($pdo->query("SELECT id, notes FROM combatants WHERE kind <> 'pc'") as $r) {
            if (preg_match('~^CR (\d+)(?:/(\d+))?~', (string) $r['notes'], $m)) {
                $st->execute([isset($m[2]) && $m[2] !== '' ? (int) $m[1] / (int) $m[2] : (float) $m[1], $r['id']]);
            }
        }
    }

    // ref = which monster this is, for its stat block: "adb:html/<book>/<file>.html",
    // "adb:custom/index.html?file=<x>.js" or "notion:<page id>". Filled in later for older rows.
    if (!in_array('ref', $cols, true)) {
        $pdo->exec('ALTER TABLE combatants ADD COLUMN ref TEXT NULL');
    }

    // App-wide settings (key/value).
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

    return $pdo;
}
