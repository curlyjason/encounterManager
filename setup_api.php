<?php
declare(strict_types=1);

/**
 * JSON API for the setup wizard (setup.php). Never returns the Notion token.
 */
require __DIR__ . '/lib/setup.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function bad(string $msg, int $code = 400): never
{
    out(['error' => $msg], $code);
}

if (!setup_allowed()) {
    bad('Setup only works from the computer running Encounter Manager (open it at http://localhost).', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bad('POST only', 405);
}
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

/** The token typed in the wizard, or the saved one when the box was left blank. */
function token_from(array $in): string
{
    $t = trim((string) ($in['token'] ?? ''));
    return $t !== '' ? $t : trim((string) (app_config()['notion_token'] ?? ''));
}

/** Columns (name => type) of one database, read fresh from Notion. */
function source_columns(array $src): array
{
    try {
        $d = notion_request('GET', 'data_sources/' . $src['data_source'], null, '2025-09-03');
    } catch (NotionError $e) {
        if (empty($src['database']) || !in_array($e->getCode(), [400, 404], true)) {
            throw $e;
        }
        $d = notion_request('GET', 'databases/' . $src['database'], null, '2022-06-28');
    }
    return array_map(fn($p) => $p['type'] ?? '?', $d['properties'] ?? []);
}

/**
 * Check one database's column choices against Notion, create the missing Number columns,
 * and return what goes in the config: columns + their types.
 */
function resolve_columns(array $src, array $fields, string $what): array
{
    $existing = source_columns($src);
    $chosen = $src['columns'] ?? [];
    $columns = [];
    $types = [];
    $create = [];
    foreach ($fields as $key => $def) {
        $c = (string) ($chosen[$key] ?? '');
        if ($c === '__create__') {
            if (empty($def['create'])) {
                bad("$what: “{$def['label']}” can't be created automatically");
            }
            $name = $def['create'];
            if (isset($existing[$name]) && $existing[$name] !== 'number') {
                bad("$what already has a column called “{$name}” ({$existing[$name]}). Pick it from the list, or rename it in Notion.");
            }
            if (!isset($existing[$name])) {
                $create[] = $name; // already there as a Number (e.g. a retry): just use it
            }
            $columns[$key] = $name;
            $types[$key] = 'number';
            continue;
        }
        if ($c === '') {
            if (!empty($def['required'])) {
                bad("$what: choose a column for “{$def['label']}”");
            }
            $columns[$key] = '';
            continue;
        }
        if (!isset($existing[$c])) {
            bad("$what has no column called “{$c}” any more. Click “Find my databases” again.");
        }
        $columns[$key] = $c;
        $types[$key] = $existing[$c];
    }
    if ($create) {
        try {
            notion_add_number_columns($src, $create);
        } catch (NotionError $e) {
            bad("Couldn't add " . implode(', ', $create) . " to $what: " . preg_replace('~^Notion: ~', '', $e->getMessage()), 502);
        }
    }
    return ['columns' => $columns, 'types' => $types, 'created' => $create];
}

try {
    switch ($in['action'] ?? '') {
        case 'status':
            $cfg = app_config();
            $camps = [];
            foreach ($cfg['campaigns'] ?? [] as $key => $c) {
                $camps[] = ['key' => $key, 'name' => $c['name'] ?? $key, 'data_source' => $c['data_source'] ?? null,
                    'columns' => $c['columns'] ?? null];
            }
            $m = $cfg['monsters'] ?? null;
            out([
                'configured'   => config_exists(),
                'has_token'    => notion_configured(),
                'requirements' => requirements(),
                'fields'       => ['character' => character_fields(), 'monster' => monster_fields()],
                'campaigns'    => $camps,
                'monsters'     => $m ? ['data_source' => $m['data_source'] ?? null, 'columns' => $m['columns'] ?? null] : null,
            ]);

        case 'test_token':
            $token = token_from($in);
            if ($token === '') {
                bad('Paste your Internal Integration Secret first');
            }
            out(with_token($token, fn() => notion_whoami()));

        case 'list_sources':
            $token = token_from($in);
            if ($token === '') {
                bad('Connect Notion first');
            }
            out(['sources' => with_token($token, fn() => notion_sources())]);

        case 'skip_notion':
            // Use the app without Notion: keep whatever is configured, just mark setup as done.
            $cfg = app_config() + ['notion_token' => '', 'campaigns' => []];
            save_config($cfg);
            out(['ok' => true]);

        case 'save':
            $token = token_from($in);
            if ($token === '') {
                bad('Connect Notion first');
            }
            $old = app_config();
            $cfg = ['notion_token' => $token, 'campaigns' => []];
            if (!empty($old['allow_remote_setup'])) {
                $cfg['allow_remote_setup'] = true;
            }
            $created = [];

            with_token($token, function () use ($in, $old, &$cfg, &$created) {
                $m = $in['monsters'] ?? null;
                if ($m && !empty($m['data_source'])) {
                    $r = resolve_columns($m, monster_fields(), 'The monster database');
                    $cfg['monsters'] = [
                        'name'        => trim((string) ($m['name'] ?? 'Monsters')),
                        'data_source' => $m['data_source'],
                        'database'    => $m['database'] ?? null,
                        'columns'     => $r['columns'],
                        'types'       => $r['types'],
                    ];
                }

                // Keep campaign keys stable so encounters stay linked to their campaign, even when a
                // campaign is unticked, saved, and ticked again later (campaign_keys remembers it).
                $oldKeys = $old['campaign_keys'] ?? [];
                foreach ($old['campaigns'] ?? [] as $k => $c) {
                    if (!empty($c['data_source'])) {
                        $oldKeys[$c['data_source']] = $k;
                    }
                }
                foreach ($in['campaigns'] ?? [] as $c) {
                    $name = trim((string) ($c['name'] ?? ''));
                    if ($name === '' || empty($c['data_source'])) {
                        bad('Every campaign needs a name');
                    }
                    $r = resolve_columns($c, character_fields(), "“{$name}”");
                    $key = $oldKeys[$c['data_source']] ?? slugify($name);
                    $base = $key;
                    for ($i = 2; isset($cfg['campaigns'][$key]); $i++) {
                        $key = "$base-$i";
                    }
                    $cfg['campaigns'][$key] = [
                        'name'        => $name,
                        'data_source' => $c['data_source'],
                        'database'    => $c['database'] ?? null,
                        'columns'     => $r['columns'],
                        'types'       => $r['types'],
                    ];
                    foreach ($r['created'] as $col) {
                        $created[] = "$col in “{$name}”";
                    }
                    $oldKeys[$c['data_source']] = $key;
                }
                $cfg['campaign_keys'] = $oldKeys;
            });

            save_config($cfg);
            out(['ok' => true, 'created' => $created, 'campaigns' => count($cfg['campaigns']), 'monsters' => isset($cfg['monsters'])]);

        default:
            bad('Unknown action');
    }
} catch (NotionError $e) {
    bad($e->getMessage(), 502);
} catch (Throwable $e) {
    bad($e->getMessage(), 500);
}
