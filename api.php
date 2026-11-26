<?php
declare(strict_types=1);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/notion.php';
require __DIR__ . '/lib/adb.php';

header('Content-Type: application/json; charset=utf-8');

const KINDS = ['pc', 'npc', 'monster'];
const CONDITIONS = [
    'Blinded', 'Charmed', 'Concentrating', 'Deafened', 'Exhaustion', 'Frightened',
    'Grappled', 'Incapacitated', 'Invisible', 'Paralyzed', 'Petrified', 'Poisoned',
    'Prone', 'Restrained', 'Stunned', 'Unconscious',
];

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function int_or_null(mixed $v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (!is_numeric($v)) {
        fail('Expected a number');
    }
    return (int) $v;
}

/** "1/4", "0.25", 3 → float CR; blank → null. */
function parse_cr(mixed $v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    $s = trim((string) $v);
    if (preg_match('~^(\d+)\s*/\s*(\d+)$~', $s, $m) && (int) $m[2] > 0) {
        return (int) $m[1] / (int) $m[2];
    }
    if (!is_numeric($s) || (float) $s < 0 || (float) $s > 30) {
        fail('CR must be 0–30 (fractions like 1/4 are fine)');
    }
    return (float) $s;
}

function parse_level(mixed $v): ?int
{
    $v = int_or_null($v);
    if ($v !== null && ($v < 1 || $v > 20)) {
        fail('Level must be 1–20');
    }
    return $v;
}

function setting(string $key, string $default): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

/**
 * Difficulty scale: the 2024 DMG XP budgets are multiplied by this before rating an encounter.
 * Default 1.5 = the official numbers run at least 50% light for this table.
 */
const DEFAULT_DIFFICULTY_SCALE = '1.5';

function d20(): int
{
    return random_int(1, 20);
}

function find_encounter(int $id): array
{
    $st = db()->prepare('SELECT * FROM encounters WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        fail('Encounter not found', 404);
    }
    return $row;
}

function find_combatant(int $id): array
{
    $st = db()->prepare('SELECT * FROM combatants WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        fail('Combatant not found', 404);
    }
    return $row;
}

/**
 * Combatants in turn order: highest initiative first, ties broken by modifier, then insertion.
 * $inCombatOnly skips anyone still waiting to join.
 */
function ordered_combatants(int $encounterId, bool $inCombatOnly = false): array
{
    $st = db()->prepare(
        'SELECT * FROM combatants WHERE encounter_id = ?' . ($inCombatOnly ? ' AND in_combat = 1 AND dead = 0' : '') . '
         ORDER BY initiative IS NULL, initiative DESC, init_mod DESC, id ASC'
    );
    $st->execute([$encounterId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        foreach (['id', 'encounter_id', 'init_mod', 'hp_max', 'hp', 'temp_hp'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['level'] = $r['level'] === null ? null : (int) $r['level'];
        $r['cr'] = $r['cr'] === null ? null : (float) $r['cr'];
        $r['in_combat'] = (bool) $r['in_combat'];
        $r['dead'] = (bool) $r['dead'];
        $r['initiative'] = $r['initiative'] === null ? null : (int) $r['initiative'];
        $r['ac'] = $r['ac'] === null ? null : (int) $r['ac'];
        $r['conditions'] = json_decode($r['conditions'], true) ?: [];
    }
    return $rows;
}

function encounter_payload(int $id): array
{
    $enc = find_encounter($id);
    $enc['id'] = (int) $enc['id'];
    $enc['round'] = (int) $enc['round'];
    $enc['active_id'] = $enc['active_id'] === null ? null : (int) $enc['active_id'];
    $enc['paused'] = (bool) $enc['paused'];
    return ['encounter' => $enc, 'combatants' => ordered_combatants($id)];
}

function set_turn(int $encounterId, int $round, ?int $activeId): void
{
    db()->prepare('UPDATE encounters SET round = ?, active_id = ? WHERE id = ?')
        ->execute([$round, $activeId, $encounterId]);
}

/** Move the turn marker forward (+1) or back (-1), wrapping rounds. */
function step_turn(int $encounterId, int $dir): void
{
    $enc = find_encounter($encounterId);
    $list = ordered_combatants($encounterId, true);
    if (!$list) {
        fail('Nobody is in the fight yet: bring some combatants in first');
    }
    $ids = array_column($list, 'id');
    $round = (int) $enc['round'];
    $pos = array_search((int) $enc['active_id'], $ids, true);

    if ($enc['active_id'] === null || $pos === false || $round === 0) {
        // Not started (or active combatant was removed): start at the top.
        set_turn($encounterId, max(1, $round), $ids[0]);
        return;
    }

    $pos += $dir;
    if ($pos >= count($ids)) {
        $pos = 0;
        $round++;
    } elseif ($pos < 0) {
        if ($round <= 1) {
            return; // already at the very first turn
        }
        $pos = count($ids) - 1;
        $round--;
    }
    set_turn($encounterId, $round, $ids[$pos]);
}

/** If $combatantId currently has the turn, pass the turn on before they leave the turn order. */
function hand_off_turn(int $encounterId, int $combatantId): void
{
    $enc = find_encounter($encounterId);
    if ((int) $enc['active_id'] !== $combatantId) {
        return;
    }
    step_turn($encounterId, 1);
    $after = find_encounter($encounterId);
    if ((int) $after['active_id'] === $combatantId) {
        // They were the only one in the fight.
        set_turn($encounterId, (int) $after['round'], null);
    }
}

/** Mark a combatant dead: they leave the turn order (passing the turn on if it was theirs). */
function mark_dead(array $c): void
{
    hand_off_turn((int) $c['encounter_id'], (int) $c['id']);
    db()->prepare('UPDATE combatants SET dead = 1 WHERE id = ?')->execute([$c['id']]);
}

/**
 * Apply the 0-HP rules after a combatant's HP changes.
 *  - Monsters and NPCs at 0 HP die.
 *  - PCs at 0 HP fall Unconscious (condition added; removed again when healed above 0).
 *  - A PC hit by a single blow of at least double their max HP dies outright.
 * $damage is the size of the hit (null when HP was typed in directly).
 */
function apply_hp_rules(int $id, ?int $damage = null): void
{
    $c = find_combatant($id);
    $hp = (int) $c['hp'];
    $max = (int) $c['hp_max'];
    if ($c['dead'] || $max <= 0) {
        return;
    }
    $conds = json_decode($c['conditions'], true) ?: [];

    if ($c['kind'] !== 'pc') {
        if ($hp === 0) {
            mark_dead($c);
        }
        return;
    }

    if ($hp === 0) {
        if (!in_array('Unconscious', $conds, true)) {
            $conds[] = 'Unconscious';
        }
        if ($damage !== null && $damage >= 2 * $max) {
            mark_dead($c);
        }
    } else {
        $conds = array_values(array_diff($conds, ['Unconscious']));
    }
    db()->prepare('UPDATE combatants SET conditions = ? WHERE id = ?')->execute([json_encode($conds), $id]);
}

/** Validate a list of combatant ids that all belong to one encounter; returns that encounter id. */
function ids_encounter(array $ids): int
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        fail('Select at least one combatant');
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT DISTINCT encounter_id FROM combatants WHERE id IN ($ph)");
    $st->execute($ids);
    $encs = $st->fetchAll(PDO::FETCH_COLUMN);
    if (count($encs) !== 1) {
        fail('Combatants not found');
    }
    return (int) $encs[0];
}

// ---------------------------------------------------------------------------

$in = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($in)) {
    fail('Invalid JSON body');
}
$action = (string) ($in['action'] ?? '');
$pdo = db();

try {
    switch ($action) {
        case 'meta':
            echo json_encode([
                'conditions' => CONDITIONS,
                'kinds'      => KINDS,
                'notion'     => notion_configured(),
                // Where monster search looks: the local 5e Artisanal Database if set up, else Notion.
                'monster_source' => adb_configured() ? 'adb' : (notion_configured() && !empty(app_config()['monsters']) ? 'notion' : null),
                'campaigns'  => campaigns(),
                'difficulty_scale' => (float) setting('difficulty_scale', DEFAULT_DIFFICULTY_SCALE),
            ]);
            break;

        case 'set_difficulty_scale':
            $v = (float) ($in['value'] ?? 0);
            if ($v < 0.25 || $v > 5) {
                fail('Scale must be between 0.25 and 5');
            }
            $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value')
                ->execute(['difficulty_scale', (string) round($v, 2)]);
            echo json_encode(['difficulty_scale' => round($v, 2)]);
            break;

        case 'set_campaign':
            $id = (int) ($in['id'] ?? 0);
            find_encounter($id);
            $key = (string) ($in['campaign'] ?? '');
            $valid = array_column(campaigns(), 'key');
            $pdo->prepare('UPDATE encounters SET campaign = ? WHERE id = ?')
                ->execute([in_array($key, $valid, true) ? $key : null, $id]);
            echo json_encode(encounter_payload($id));
            break;

        case 'add_party':
            // Pull PCs from the encounter's campaign in Notion; skip anyone already in the fight.
            $encId = (int) ($in['encounter_id'] ?? 0);
            $enc = find_encounter($encId);
            if (!$enc['campaign']) {
                fail('Pick a campaign for this encounter first');
            }
            $party = campaign_party($enc['campaign']);
            $have = array_map('mb_strtolower', array_column(ordered_combatants($encId), 'name'));
            $st = $pdo->prepare(
                "INSERT INTO combatants (encounter_id, name, kind, init_mod, ac, hp_max, hp, notes, level)
                 VALUES (?, ?, 'pc', ?, ?, ?, ?, ?, ?)"
            );
            $added = 0;
            $missing = [];
            $pdo->beginTransaction();
            foreach ($party as $pc) {
                if (in_array(mb_strtolower($pc['name']), $have, true)) {
                    continue;
                }
                $notes = $pc['player'] !== '' ? 'Player: ' . $pc['player'] : '';
                $st->execute([$encId, $pc['name'], $pc['init_mod'] ?? 0, $pc['ac'], $pc['hp_max'], $pc['hp_max'], $notes, $pc['level']]);
                $added++;
                if ($pc['hp_max'] === 0 || $pc['ac'] === null || $pc['level'] === null) {
                    $missing[] = $pc['name'];
                }
            }
            $pdo->commit();
            echo json_encode(encounter_payload($encId) + ['added' => $added, 'missing_stats' => $missing]);
            break;

        case 'refresh_party':
            // Re-read AC / Max HP / Init Mod from Notion for PCs already in the encounter (matched by name).
            // Current HP: a PC at full (or never set) stays at full; a wounded PC keeps their HP, capped at the new max.
            $encId = (int) ($in['encounter_id'] ?? 0);
            $enc = find_encounter($encId);
            if (!$enc['campaign']) {
                fail('Pick a campaign for this encounter first');
            }
            $byName = [];
            foreach (campaign_party($enc['campaign']) as $pc) {
                $byName[mb_strtolower($pc['name'])] = $pc;
            }
            $st = $pdo->prepare('UPDATE combatants SET ac = ?, init_mod = ?, hp_max = ?, hp = ?, level = ? WHERE id = ?');
            $updated = 0;
            $notFound = [];
            $missing = [];
            $pdo->beginTransaction();
            foreach (ordered_combatants($encId) as $c) {
                if ($c['kind'] !== 'pc') {
                    continue;
                }
                $pc = $byName[mb_strtolower($c['name'])] ?? null;
                if (!$pc) {
                    $notFound[] = $c['name'];
                    continue;
                }
                // Blank values in Notion leave whatever is already in the app untouched.
                $ac = $pc['ac'] ?? $c['ac'];
                $mod = $pc['init_mod'] ?? $c['init_mod'];
                $max = $pc['hp_max'] > 0 ? $pc['hp_max'] : $c['hp_max'];
                $wasFull = $c['hp_max'] === 0 || $c['hp'] >= $c['hp_max'];
                $hp = $wasFull ? $max : min($c['hp'], $max);
                $st->execute([$ac, $mod, $max, $hp, $pc['level'] ?? $c['level'], $c['id']]);
                $updated++;
                if ($pc['hp_max'] === 0 || $pc['ac'] === null || $pc['level'] === null) {
                    $missing[] = $pc['name'];
                }
            }
            $pdo->commit();
            echo json_encode(encounter_payload($encId) + [
                'updated' => $updated, 'not_found' => $notFound, 'missing_stats' => $missing,
            ]);
            break;

        case 'search_monsters':
            $q = trim((string) ($in['q'] ?? ''));
            $crMin = isset($in['cr_min']) && $in['cr_min'] !== '' ? (float) $in['cr_min'] : null;
            $crMax = isset($in['cr_max']) && $in['cr_max'] !== '' ? (float) $in['cr_max'] : null;
            if (mb_strlen($q) < 2 && $crMin === null && $crMax === null) {
                echo json_encode(['monsters' => [], 'more' => false]);
                break;
            }
            $q = mb_strlen($q) < 2 ? '' : $q;
            echo json_encode(adb_configured() ? adb_search_monsters($q, $crMin, $crMax) : search_monsters($q, $crMin, $crMax));
            break;

        case 'list_encounters':
            $rows = $pdo->query(
                'SELECT e.id, e.name, e.round, e.created_at,
                        (SELECT COUNT(*) FROM combatants c WHERE c.encounter_id = e.id) AS count
                 FROM encounters e ORDER BY e.id DESC'
            )->fetchAll();
            echo json_encode(['encounters' => $rows]);
            break;

        case 'create_encounter':
            $name = trim((string) ($in['name'] ?? ''));
            if ($name === '') {
                fail('Name is required');
            }
            $pdo->prepare('INSERT INTO encounters (name) VALUES (?)')->execute([$name]);
            echo json_encode(encounter_payload((int) $pdo->lastInsertId()));
            break;

        case 'rename_encounter':
            $id = (int) ($in['id'] ?? 0);
            find_encounter($id);
            $name = trim((string) ($in['name'] ?? ''));
            if ($name === '') {
                fail('Name is required');
            }
            $pdo->prepare('UPDATE encounters SET name = ? WHERE id = ?')->execute([$name, $id]);
            echo json_encode(encounter_payload($id));
            break;

        case 'delete_encounter':
            $id = (int) ($in['id'] ?? 0);
            find_encounter($id);
            $pdo->prepare('DELETE FROM encounters WHERE id = ?')->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        case 'duplicate_encounter':
            $id = (int) ($in['id'] ?? 0);
            $src = find_encounter($id);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO encounters (name, campaign) VALUES (?, ?)')
                ->execute([$src['name'] . ' (copy)', $src['campaign']]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO combatants (encounter_id, name, kind, init_mod, ac, hp_max, hp, notes, in_combat, level, cr)
                 SELECT ?, name, kind, init_mod, ac, hp_max, hp_max, notes, in_combat, level, cr FROM combatants WHERE encounter_id = ?'
            )->execute([$newId, $id]);
            $pdo->commit();
            echo json_encode(encounter_payload($newId));
            break;

        case 'get_encounter':
            echo json_encode(encounter_payload((int) ($in['id'] ?? 0)));
            break;

        case 'add_combatant':
            $encId = (int) ($in['encounter_id'] ?? 0);
            find_encounter($encId);
            $name = trim((string) ($in['name'] ?? ''));
            if ($name === '') {
                fail('Name is required');
            }
            $kind = in_array($in['kind'] ?? '', KINDS, true) ? $in['kind'] : 'monster';
            $mod = int_or_null($in['init_mod'] ?? 0) ?? 0;
            $ac = int_or_null($in['ac'] ?? null);
            $hpMax = max(0, int_or_null($in['hp_max'] ?? 0) ?? 0);
            $init = int_or_null($in['initiative'] ?? null);
            $count = max(1, min(30, (int) ($in['count'] ?? 1)));
            $notes = (string) ($in['notes'] ?? '');
            $inCombat = empty($in['waiting']) ? 1 : 0;
            $cr = $kind === 'pc' ? null : parse_cr($in['cr'] ?? null);
            $level = $kind === 'pc' ? parse_level($in['level'] ?? null) : null;

            // Continue numbering if this monster is already in the encounter (Goblin 4, 5… after Goblin 1–3).
            $st = $pdo->prepare('SELECT name FROM combatants WHERE encounter_id = ?');
            $st->execute([$encId]);
            $start = 0;
            $exact = false;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    $exact = true;
                } elseif (preg_match('/^' . preg_quote($name, '/') . ' (\d+)$/i', $existing, $m)) {
                    $start = max($start, (int) $m[1]);
                }
            }
            if ($exact) {
                $start = max($start, 1);
            }
            $numbered = $count > 1 || $start > 0;

            $st = $pdo->prepare(
                'INSERT INTO combatants (encounter_id, name, kind, init_mod, initiative, ac, hp_max, hp, notes, in_combat, cr, level)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $pdo->beginTransaction();
            for ($i = 1; $i <= $count; $i++) {
                $n = $numbered ? $name . ' ' . ($start + $i) : $name;
                $st->execute([$encId, $n, $kind, $mod, $init, $ac, $hpMax, $hpMax, $notes, $inCombat, $cr, $level]);
            }
            $pdo->commit();
            echo json_encode(encounter_payload($encId));
            break;

        case 'update_combatant':
            $c = find_combatant((int) ($in['id'] ?? 0));
            $fields = $in['fields'] ?? [];
            if (!is_array($fields)) {
                fail('fields must be an object');
            }
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                switch ($k) {
                    case 'name':
                        $v = trim((string) $v);
                        if ($v === '') {
                            fail('Name is required');
                        }
                        break;
                    case 'notes':
                        $v = (string) $v;
                        break;
                    case 'kind':
                        if (!in_array($v, KINDS, true)) {
                            fail('Invalid kind');
                        }
                        break;
                    case 'initiative':
                    case 'ac':
                        $v = int_or_null($v);
                        break;
                    case 'init_mod':
                    case 'hp_max':
                    case 'hp':
                    case 'temp_hp':
                        $v = int_or_null($v) ?? 0;
                        if ($k !== 'init_mod') {
                            $v = max(0, $v);
                        }
                        break;
                    case 'conditions':
                        $v = json_encode(array_values(array_intersect(CONDITIONS, (array) $v)));
                        break;
                    case 'level':
                        $v = parse_level($v);
                        break;
                    case 'cr':
                        $v = parse_cr($v);
                        break;
                    default:
                        fail("Unknown field: $k");
                }
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            if ($sets) {
                $vals[] = $c['id'];
                $pdo->prepare('UPDATE combatants SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
                if (array_key_exists('hp', $fields) || array_key_exists('hp_max', $fields)) {
                    apply_hp_rules((int) $c['id']);
                }
            }
            echo json_encode(encounter_payload((int) $c['encounter_id']));
            break;

        case 'adjust_hp':
            // amount < 0 = damage (temp HP absorbs first), amount > 0 = healing (capped at max).
            $c = find_combatant((int) ($in['id'] ?? 0));
            $amt = (int) ($in['amount'] ?? 0);
            if ($c['dead']) {
                fail($c['name'] . ' is dead: revive them first');
            }
            $hp = (int) $c['hp'];
            $temp = (int) $c['temp_hp'];
            $dmg = null;
            if ($amt < 0) {
                $dmg = -$amt;
                $absorbed = min($temp, $dmg);
                $temp -= $absorbed;
                $hp = max(0, $hp - ($dmg - $absorbed));
            } else {
                $hp = min((int) $c['hp_max'], $hp + $amt);
            }
            $pdo->prepare('UPDATE combatants SET hp = ?, temp_hp = ? WHERE id = ?')->execute([$hp, $temp, $c['id']]);
            apply_hp_rules((int) $c['id'], $dmg);
            echo json_encode(encounter_payload((int) $c['encounter_id']));
            break;

        case 'remove_combatant':
            $c = find_combatant((int) ($in['id'] ?? 0));
            $encId = (int) $c['encounter_id'];
            hand_off_turn($encId, (int) $c['id']);
            $pdo->prepare('DELETE FROM combatants WHERE id = ?')->execute([$c['id']]);
            echo json_encode(encounter_payload($encId));
            break;

        case 'roll_initiative':
            // which: 'all' | 'monsters' (non-PCs) | 'unrolled'
            $encId = (int) ($in['encounter_id'] ?? 0);
            find_encounter($encId);
            $which = (string) ($in['which'] ?? 'unrolled');
            $st = $pdo->prepare('UPDATE combatants SET initiative = ? WHERE id = ?');
            $pdo->beginTransaction();
            foreach (ordered_combatants($encId, true) as $c) { // waiting combatants roll when they join
                $roll = match ($which) {
                    'all'      => true,
                    'monsters' => $c['kind'] !== 'pc',
                    default    => $c['initiative'] === null,
                };
                if ($roll) {
                    $st->execute([d20() + $c['init_mod'], $c['id']]);
                }
            }
            $pdo->commit();
            echo json_encode(encounter_payload($encId));
            break;

        case 'hold_back':
            // Move combatants out of the turn order into "waiting to join".
            $ids = array_map('intval', (array) ($in['ids'] ?? []));
            $encId = ids_encounter($ids);
            // If the turn is on someone being held back, keep passing it until it lands on someone staying.
            for ($guard = 0; $guard <= count($ids); $guard++) {
                $active = find_encounter($encId)['active_id'];
                if ($active === null || !in_array((int) $active, $ids, true)) {
                    break;
                }
                hand_off_turn($encId, (int) $active);
            }
            $active = find_encounter($encId)['active_id'];
            if ($active !== null && in_array((int) $active, $ids, true)) {
                set_turn($encId, (int) find_encounter($encId)['round'], null); // everyone is being held back
            }
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE combatants SET in_combat = 0, initiative = NULL WHERE id IN ($ph)")->execute($ids);
            echo json_encode(encounter_payload($encId));
            break;

        case 'bring_in':
            // Waiting combatants join the fight; each rolls initiative (d20 + mod) unless one was typed in.
            $ids = array_map('intval', (array) ($in['ids'] ?? []));
            $encId = ids_encounter($ids);
            $st = $pdo->prepare('UPDATE combatants SET in_combat = 1, initiative = COALESCE(initiative, ?) WHERE id = ?');
            $sel = $pdo->prepare('SELECT init_mod FROM combatants WHERE id = ?');
            $pdo->beginTransaction();
            foreach ($ids as $id) {
                $sel->execute([$id]);
                $st->execute([d20() + (int) $sel->fetchColumn(), $id]);
            }
            $pdo->commit();
            echo json_encode(encounter_payload($encId) + ['joined' => $ids]);
            break;

        case 'next_turn':
        case 'prev_turn':
            $encId = (int) ($in['encounter_id'] ?? 0);
            if (find_encounter($encId)['paused']) {
                fail('Combat is paused: resume it first');
            }
            step_turn($encId, $action === 'next_turn' ? 1 : -1);
            echo json_encode(encounter_payload($encId));
            break;

        case 'kill':
            // Mark someone dead by hand (e.g. a PC who failed their death saves).
            $c = find_combatant((int) ($in['id'] ?? 0));
            mark_dead($c);
            echo json_encode(encounter_payload((int) $c['encounter_id']));
            break;

        case 'revive':
            // Back from the dead (or undoing a mistake): rejoins the turn order with at least 1 HP.
            $c = find_combatant((int) ($in['id'] ?? 0));
            $hp = max(1, min((int) $c['hp'], (int) $c['hp_max']));
            if ((int) $c['hp_max'] === 0) {
                $hp = 0;
            }
            $conds = array_values(array_diff(json_decode($c['conditions'], true) ?: [], ['Unconscious']));
            $pdo->prepare('UPDATE combatants SET dead = 0, hp = ?, conditions = ? WHERE id = ?')
                ->execute([$hp, json_encode($conds), $c['id']]);
            echo json_encode(encounter_payload((int) $c['encounter_id']));
            break;

        case 'set_paused':
            // Pause keeps the round and whose turn it is; resume picks up exactly there.
            $encId = (int) ($in['encounter_id'] ?? 0);
            $enc = find_encounter($encId);
            if (!empty($in['paused']) && (int) $enc['round'] === 0) {
                fail('Combat has not started yet');
            }
            $pdo->prepare('UPDATE encounters SET paused = ? WHERE id = ?')->execute([empty($in['paused']) ? 0 : 1, $encId]);
            echo json_encode(encounter_payload($encId));
            break;

        case 'reset_encounter':
            // Stop combat: back to round 0 with nobody's turn, and (optionally) a fresh fight.
            $encId = (int) ($in['encounter_id'] ?? 0);
            find_encounter($encId);
            set_turn($encId, 0, null);
            $pdo->prepare('UPDATE encounters SET paused = 0 WHERE id = ?')->execute([$encId]);
            if (!empty($in['heal'])) {
                $pdo->prepare("UPDATE combatants SET hp = hp_max, temp_hp = 0, conditions = '[]', dead = 0 WHERE encounter_id = ?")
                    ->execute([$encId]);
            }
            if (!empty($in['clear_initiative'])) {
                $pdo->prepare('UPDATE combatants SET initiative = NULL WHERE encounter_id = ?')->execute([$encId]);
            }
            echo json_encode(encounter_payload($encId));
            break;

        default:
            fail('Unknown action');
    }
} catch (NotionError $e) {
    fail($e->getMessage(), 502);
} catch (AdbError $e) {
    fail('5e Artisanal Database: ' . $e->getMessage(), 500);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fail('Database error: ' . $e->getMessage(), 500);
}
