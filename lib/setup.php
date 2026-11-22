<?php
declare(strict_types=1);

/**
 * First-run / settings helpers: requirements check, writing lib/config.php,
 * finding Notion databases and adding missing columns.
 */
require_once __DIR__ . '/notion.php';

const CONFIG_FILE = __DIR__ . '/config.php';

function config_exists(): bool
{
    return is_file(CONFIG_FILE);
}

/** Setup can rewrite the config (and holds the token), so only allow it from this computer. */
function setup_allowed(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1'], true) || !empty(app_config()['allow_remote_setup']);
}

function requirements(): array
{
    $dataDir = __DIR__ . '/../data';
    return [
        ['label' => 'PHP 8.1 or newer', 'ok' => PHP_VERSION_ID >= 80100, 'detail' => 'You have ' . PHP_VERSION],
        ['label' => 'SQLite (pdo_sqlite extension)', 'ok' => extension_loaded('pdo_sqlite'),
            'detail' => 'Enable extension=pdo_sqlite in php.ini'],
        ['label' => 'cURL (curl extension), to talk to Notion', 'ok' => extension_loaded('curl'),
            'detail' => 'Enable extension=curl in php.ini'],
        ['label' => 'data/ folder is writable', 'ok' => is_writable($dataDir), 'detail' => realpath($dataDir) ?: $dataDir],
        ['label' => 'lib/ folder is writable (to save settings)',
            'ok' => is_writable(__DIR__) || (config_exists() && is_writable(CONFIG_FILE)), 'detail' => realpath(__DIR__) ?: __DIR__],
    ];
}

/** Write lib/config.php atomically, readable only by this user. Keeps one backup of the previous file. */
function save_config(array $cfg): void
{
    if (config_exists()) {
        @copy(CONFIG_FILE, __DIR__ . '/config.backup.php');
        @chmod(__DIR__ . '/config.backup.php', 0600);
    }
    $php = "<?php\n// Written by Encounter Manager setup (setup.php). Keep this file private: it holds your Notion token.\n"
        . 'return ' . var_export($cfg, true) . ";\n";
    $tmp = CONFIG_FILE . '.tmp';
    if (@file_put_contents($tmp, $php) === false) {
        throw new RuntimeException('Could not write ' . CONFIG_FILE . ': check that the lib/ folder is writable');
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, CONFIG_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('Could not replace ' . CONFIG_FILE);
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(CONFIG_FILE, true);
    }
}

/** Run a Notion call with a specific token (the one being tested), not the saved one. */
function with_token(string $token, callable $fn)
{
    $GLOBALS['NOTION_TOKEN_OVERRIDE'] = $token;
    try {
        return $fn();
    } finally {
        unset($GLOBALS['NOTION_TOKEN_OVERRIDE']);
    }
}

function notion_whoami(): array
{
    $me = notion_request('GET', 'users/me', null, '2025-09-03');
    return [
        'integration' => $me['name'] ?? 'your integration',
        'workspace'   => $me['bot']['workspace_name'] ?? null,
    ];
}

function rich_title(array $parts): string
{
    return trim(implode('', array_map(fn($t) => $t['plain_text'] ?? '', $parts)));
}

/** Title and parent page of a page ('' / null when it isn't shared with the integration). */
function notion_page_info(string $pageId): array
{
    static $cache = [];
    if (!isset($cache[$pageId])) {
        $info = ['title' => '', 'parent' => null];
        try {
            $page = notion_request('GET', 'pages/' . $pageId, null, '2025-09-03');
            foreach ($page['properties'] ?? [] as $p) {
                if (($p['type'] ?? '') === 'title') {
                    $info['title'] = rich_title($p['title'] ?? []);
                }
            }
            $info['parent'] = ($page['parent']['type'] ?? '') === 'page_id' ? $page['parent']['page_id'] : null;
        } catch (NotionError) {
            // not shared with the integration
        }
        $cache[$pageId] = $info;
    }
    return $cache[$pageId];
}

/**
 * Where a database lives, to tell apart several "Characters" databases.
 * Skips parent pages that just repeat the database's name (e.g. a "Characters" page holding "Characters").
 */
function notion_location(?string $pageId, string $dbTitle): string
{
    $title = '';
    for ($i = 0; $pageId && $i < 3; $i++) {
        $info = notion_page_info($pageId);
        $title = $info['title'];
        if ($title !== '' && strcasecmp($title, $dbTitle) !== 0) {
            return $title;
        }
        $pageId = $info['parent'];
    }
    return $title;
}

/** Every database the integration can see, with its columns (name => type). */
function notion_sources(): array
{
    $out = [];
    $cursor = null;
    $legacy = false;
    do {
        $body = ['page_size' => 100];
        if ($cursor) {
            $body['start_cursor'] = $cursor;
        }
        try {
            $res = notion_request('POST', 'search', $body + ['filter' => ['property' => 'object', 'value' => $legacy ? 'database' : 'data_source']],
                $legacy ? '2022-06-28' : '2025-09-03');
        } catch (NotionError $e) {
            if ($legacy || !in_array($e->getCode(), [400, 404], true)) {
                throw $e;
            }
            $legacy = true;
            continue;
        }
        foreach ($res['results'] ?? [] as $d) {
            if (count($out) >= 300) {
                break;
            }
            if (!empty($d['in_trash']) || !empty($d['archived'])) {
                continue;
            }
            $parent = $legacy ? ($d['parent'] ?? []) : ($d['database_parent'] ?? []);
            $out[] = [
                'data_source'  => $legacy ? $d['id'] : $d['id'],
                'database'     => $legacy ? $d['id'] : ($d['parent']['database_id'] ?? null),
                'title'        => rich_title($d['title'] ?? []) ?: 'Untitled',
                'parent_title' => notion_location(($parent['type'] ?? '') === 'page_id' ? $parent['page_id'] : null,
                    rich_title($d['title'] ?? [])),
                'url'          => $d['url'] ?? null,
                'properties'   => array_map(fn($p) => $p['type'] ?? '?', $d['properties'] ?? []),
            ];
        }
        $cursor = !empty($res['has_more']) ? ($res['next_cursor'] ?? null) : null;
    } while ($cursor && count($out) < 300);

    usort($out, fn($a, $b) => [$a['title'], $a['parent_title']] <=> [$b['title'], $b['parent_title']]);
    return $out;
}

/** Add Number columns to a database. Needs the integration's "Update content" capability. */
function notion_add_number_columns(array $src, array $names): void
{
    if (!$names) {
        return;
    }
    $props = [];
    foreach ($names as $n) {
        $props[$n] = ['number' => ['format' => 'number']];
    }
    try {
        notion_request('PATCH', 'data_sources/' . $src['data_source'], ['properties' => $props], '2025-09-03');
    } catch (NotionError $e) {
        if (empty($src['database']) || !in_array($e->getCode(), [400, 404], true)) {
            throw $e;
        }
        notion_request('PATCH', 'databases/' . $src['database'], ['properties' => $props], '2022-06-28');
    }
}

function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('~[^A-Za-z0-9]+~', '-', $s), '-'));
    return $s !== '' ? substr($s, 0, 40) : 'campaign';
}
