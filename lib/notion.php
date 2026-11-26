<?php
declare(strict_types=1);

/**
 * Minimal read-only Notion client for encounterManager.
 * Uses the data-sources API, falling back to the older databases API.
 */
final class NotionError extends RuntimeException {}

function app_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/config.php';
        $cfg = is_file($file) ? (require $file) : [];
    }
    return $cfg;
}

function notion_configured(): bool
{
    return trim((string) (app_config()['notion_token'] ?? '')) !== '';
}

function notion_request(string $method, string $path, ?array $body, string $version): array
{
    // Setup tests a token before it's saved; everything else uses the saved one.
    $token = trim((string) ($GLOBALS['NOTION_TOKEN_OVERRIDE'] ?? app_config()['notion_token'] ?? ''));
    if ($token === '') {
        throw new NotionError('Notion is not connected yet: open Setup to add your token');
    }

    $ch = curl_init('https://api.notion.com/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Notion-Version: ' . $version,
            'Content-Type: application/json',
        ],
    ]);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
        // Windows PHP ships without a certificate bundle; use the system's certificates instead.
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new NotionError('Could not reach Notion: ' . $err);
    }
    $json = json_decode($raw, true) ?: [];
    if ($code >= 400) {
        $msg = $json['message'] ?? "HTTP $code";
        if ($code === 401) {
            $msg = 'the token was rejected. Copy the Internal Integration Secret again from notion.so/profile/integrations';
        } elseif ($code === 403 && $method === 'PATCH') {
            $msg = 'the integration may not change databases. Turn on its "Update content" capability, or add the columns by hand';
        }
        $e = new NotionError("Notion: $msg", $code);
        throw $e;
    }
    return $json;
}

/** Query a database; $src has 'data_source' and/or 'database' ids. Returns all pages up to $max. */
function notion_query(array $src, array $query, int $max = 100): array
{
    $results = [];
    $cursor = null;
    do {
        $body = $query + ['page_size' => min(100, $max - count($results))];
        if ($cursor) {
            $body['start_cursor'] = $cursor;
        }
        try {
            $res = notion_request('POST', 'data_sources/' . $src['data_source'] . '/query', $body, '2025-09-03');
        } catch (NotionError $e) {
            // Older API / integrations: query the database instead.
            if (empty($src['database']) || !in_array($e->getCode(), [400, 404], true)) {
                throw $e;
            }
            $res = notion_request('POST', 'databases/' . $src['database'] . '/query', $body, '2022-06-28');
        }
        array_push($results, ...($res['results'] ?? []));
        $cursor = !empty($res['has_more']) ? ($res['next_cursor'] ?? null) : null;
    } while ($cursor && count($results) < $max);

    return $results;
}

// ---- property readers -------------------------------------------------------

/** Text from almost any property type (title, text, url, select, status, people, checkbox, number, formula). */
function np_text(array $page, ?string $name): string
{
    $p = $name === null ? null : ($page['properties'][$name] ?? null);
    if (!$p) {
        return '';
    }
    $join = fn(array $parts) => trim(implode('', array_map(fn($t) => $t['plain_text'] ?? '', $parts)));
    switch ($p['type'] ?? '') {
        case 'title':
        case 'rich_text':    return $join($p[$p['type']] ?? []);
        case 'url':
        case 'email':
        case 'phone_number': return trim((string) ($p[$p['type']] ?? ''));
        case 'select':
        case 'status':       return trim((string) ($p[$p['type']]['name'] ?? ''));
        case 'multi_select': return implode(', ', array_map(fn($o) => $o['name'] ?? '', $p['multi_select'] ?? []));
        case 'people':       return implode(', ', array_filter(array_map(fn($u) => $u['name'] ?? '', $p['people'] ?? [])));
        case 'checkbox':     return !empty($p['checkbox']) ? 'Yes' : '';
        case 'number':       return $p['number'] === null ? '' : (string) $p['number'];
        case 'formula':
            $f = $p['formula'] ?? [];
            $v = $f[$f['type'] ?? ''] ?? null;
            return is_bool($v) ? ($v ? 'Yes' : '') : trim((string) $v);
    }
    // Older pages without "type": best effort.
    $parts = $p['title'] ?? $p['rich_text'] ?? null;
    return is_array($parts) ? $join($parts) : trim((string) ($p['url'] ?? $p['select']['name'] ?? ''));
}

/** A number from a number / formula / rollup property, or parsed from text ("1/2", "+3", "15 (natural armor)"). */
function np_num(array $page, ?string $name): ?float
{
    $p = $name === null ? null : ($page['properties'][$name] ?? null);
    if (!$p) {
        return null;
    }
    $type = $p['type'] ?? 'number';
    if ($type === 'number') {
        return is_numeric($p['number'] ?? null) ? (float) $p['number'] : null;
    }
    if ($type === 'formula' && ($p['formula']['type'] ?? '') === 'number') {
        return is_numeric($p['formula']['number'] ?? null) ? (float) $p['formula']['number'] : null;
    }
    if ($type === 'rollup' && ($p['rollup']['type'] ?? '') === 'number') {
        return is_numeric($p['rollup']['number'] ?? null) ? (float) $p['rollup']['number'] : null;
    }
    return parse_number_text(np_text($page, $name));
}

function parse_number_text(string $t): ?float
{
    $t = trim($t);
    if (preg_match('~^([+-]?\d+)\s*/\s*(\d+)~', $t, $m) && (int) $m[2] !== 0) {
        return (int) $m[1] / (int) $m[2];
    }
    if (preg_match('~^[+-]?\d+(\.\d+)?~', $t, $m)) {
        return (float) $m[0];
    }
    return null;
}

// ---- column mapping ---------------------------------------------------------

/**
 * Which Notion columns the app reads. 'create' = setup may add it as a Number column.
 * 'match' = names tried (case-insensitively) when guessing the mapping.
 */
function character_fields(): array
{
    return [
        'name'     => ['label' => 'Character name', 'types' => ['title'], 'required' => true, 'match' => ['Name']],
        'player'   => ['label' => 'Player', 'types' => ['rich_text', 'select', 'people', 'title'], 'match' => ['Player', 'Player Name', 'Played By']],
        'level'    => ['label' => 'Level', 'types' => ['number', 'formula', 'rollup', 'rich_text', 'select'], 'create' => 'Level', 'match' => ['Level', 'Lvl', 'Character Level']],
        'ac'       => ['label' => 'Armor Class', 'types' => ['number', 'formula', 'rollup', 'rich_text'], 'create' => 'AC', 'match' => ['AC', 'Armor Class']],
        'hp_max'   => ['label' => 'Max HP', 'types' => ['number', 'formula', 'rollup', 'rich_text'], 'create' => 'Max HP', 'match' => ['Max HP', 'HP', 'Hit Points', 'Max Hit Points']],
        'init_mod' => ['label' => 'Initiative modifier', 'types' => ['number', 'formula', 'rollup', 'rich_text'], 'create' => 'Init Mod', 'match' => ['Init Mod', 'Initiative', 'Initiative Mod', 'Initiative Modifier', 'Init']],
        'link'     => ['label' => 'Character sheet link', 'types' => ['url', 'rich_text'], 'match' => ['Link', 'URL', 'D&D Beyond', 'DDB', 'Sheet']],
    ];
}

function monster_fields(): array
{
    return [
        'name'      => ['label' => 'Monster name', 'types' => ['title'], 'required' => true, 'match' => ['Name']],
        'cr'        => ['label' => 'Challenge rating', 'types' => ['number', 'formula', 'rich_text', 'select'], 'match' => ['CR', 'Challenge', 'Challenge Rating']],
        'ac'        => ['label' => 'Armor Class', 'types' => ['number', 'formula', 'rich_text'], 'match' => ['AC', 'Armor Class']],
        'hp_max'    => ['label' => 'Hit points', 'types' => ['number', 'formula', 'rich_text'], 'match' => ['Hit Points', 'HP', 'Max HP']],
        'init_mod'  => ['label' => 'Initiative modifier', 'types' => ['number', 'formula', 'rich_text'], 'match' => ['Initiative', 'Init Mod', 'Initiative Mod', 'Initiative Modifier', 'Init', 'Dex Mod']],
        'type'      => ['label' => 'Creature type', 'types' => ['select', 'multi_select', 'rich_text'], 'match' => ['Type', 'Creature Type']],
        'source'    => ['label' => 'Source book', 'types' => ['select', 'multi_select', 'rich_text'], 'match' => ['Source', 'Book']],
        'legendary' => ['label' => 'Legendary', 'types' => ['select', 'checkbox', 'rich_text', 'multi_select'], 'match' => ['Legendary']],
    ];
}

/** Column name for a field: from config, else the original default (first 'match' name). */
function col(array $src, array $fields, string $field): ?string
{
    if (array_key_exists('columns', $src)) {
        $c = $src['columns'][$field] ?? null;
        return $c === '' ? null : $c;
    }
    return $fields[$field]['match'][0] ?? null; // configs written before setup existed
}

// ---- domain -----------------------------------------------------------------

function campaigns(): array
{
    $out = [];
    foreach (app_config()['campaigns'] ?? [] as $key => $c) {
        $out[] = ['key' => $key, 'name' => $c['name'] ?? $key];
    }
    return $out;
}

/** PCs from a campaign's Characters database. */
function campaign_party(string $key): array
{
    $c = app_config()['campaigns'][$key] ?? null;
    if (!$c) {
        throw new NotionError('Unknown campaign');
    }
    $f = character_fields();
    $nameCol = col($c, $f, 'name') ?? 'Name';
    $pages = notion_query($c, ['sorts' => [['property' => $nameCol, 'direction' => 'ascending']]]);
    $num = fn($p, $field) => ($v = np_num($p, col($c, $f, $field))) === null ? null : (int) round($v);
    $party = [];
    foreach ($pages as $p) {
        $name = np_text($p, $nameCol);
        if ($name === '') {
            continue;
        }
        $party[] = [
            'name'     => $name,
            'player'   => np_text($p, col($c, $f, 'player')),
            'ac'       => $num($p, 'ac'),
            'hp_max'   => (int) ($num($p, 'hp_max') ?? 0),
            'init_mod' => $num($p, 'init_mod'), // null = blank in Notion
            'level'    => $num($p, 'level'),
            'link'     => np_text($p, col($c, $f, 'link')),
            'ref'      => isset($p['id']) ? 'notion:' . strtolower($p['id']) : null,
        ];
    }
    return $party;
}

function format_cr(?float $cr): string
{
    if ($cr === null) {
        return '?';
    }
    return match (true) {
        abs($cr - 0.125) < 0.001 => '1/8',
        abs($cr - 0.25) < 0.001  => '1/4',
        abs($cr - 0.5) < 0.001   => '1/2',
        default                  => (string) (int) $cr,
    };
}

/**
 * Search the monster database by (partial) name and/or CR range.
 * Returns ['monsters' => [...], 'more' => bool] where 'more' means the limit was hit.
 */
function search_monsters(string $q, ?float $crMin = null, ?float $crMax = null, int $limit = 50): array
{
    $src = app_config()['monsters'] ?? null;
    if (!$src) {
        throw new NotionError('Monster database is not configured: run Setup');
    }
    $f = monster_fields();
    $nameCol = col($src, $f, 'name') ?? 'Name';
    $crCol = col($src, $f, 'cr');
    $crType = $src['types']['cr'] ?? 'number';
    // Notion can filter and sort CR itself only when it's a number (or numeric formula).
    $crServer = $crCol !== null && in_array($crType, ['number', 'formula'], true);

    $filters = [];
    if ($q !== '') {
        $filters[] = ['property' => $nameCol, 'title' => ['contains' => $q]];
    }
    if ($crServer) {
        $cond = $crType === 'formula' ? fn($c) => ['formula' => ['number' => $c]] : fn($c) => ['number' => $c];
        if ($crMin !== null) {
            $filters[] = ['property' => $crCol] + $cond(['greater_than_or_equal_to' => $crMin]);
        }
        if ($crMax !== null) {
            $filters[] = ['property' => $crCol] + $cond(['less_than_or_equal_to' => $crMax]);
        }
    }
    $sorts = $crServer ? [['property' => $crCol, 'direction' => 'ascending']] : [];
    $sorts[] = ['property' => $nameCol, 'direction' => 'ascending'];
    $query = ['sorts' => $sorts];
    if (count($filters) === 1) {
        $query['filter'] = $filters[0];
    } elseif ($filters) {
        $query['filter'] = ['and' => $filters];
    }
    $localCr = !$crServer && $crCol !== null && ($crMin !== null || $crMax !== null);
    // Ask for one extra row so we can tell the user there are more (more rows when filtering CR ourselves).
    $pages = notion_query($src, $query, $localCr ? 500 : $limit + 1);

    $out = [];
    foreach ($pages as $p) {
        $cr = np_num($p, $crCol);
        if ($localCr && ($cr === null || ($crMin !== null && $cr < $crMin) || ($crMax !== null && $cr > $crMax))) {
            continue;
        }
        $out[] = [
            'name'      => np_text($p, $nameCol),
            'cr'        => format_cr($cr),
            'cr_value'  => $cr,
            'ac'        => ($v = np_num($p, col($src, $f, 'ac'))) === null ? null : (int) $v,
            'hp_max'    => (int) (np_num($p, col($src, $f, 'hp_max')) ?? 0),
            'init_mod'  => (int) (np_num($p, col($src, $f, 'init_mod')) ?? 0),
            'type'      => np_text($p, col($src, $f, 'type')),
            'source'    => np_text($p, col($src, $f, 'source')),
            'legendary' => !in_array(strtolower(np_text($p, col($src, $f, 'legendary'))), ['', 'no', 'false', '-'], true),
            'ref'       => isset($p['id']) ? 'notion:' . strtolower($p['id']) : null,
            'link'      => $p['url'] ?? null,
        ];
    }
    if (!$crServer) {
        usort($out, fn($a, $b) => [$a['cr_value'] ?? 99, $a['name']] <=> [$b['cr_value'] ?? 99, $b['name']]);
    }
    $more = count($out) > $limit;
    $out = array_slice($out, 0, $limit);
    // With a name, put exact / prefix matches first; otherwise keep the CR, Name order.
    if ($q !== '') {
        $ql = mb_strtolower($q);
        $rank = fn($n) => mb_strtolower($n) === $ql ? 0 : (str_starts_with(mb_strtolower($n), $ql) ? 1 : 2);
        usort($out, fn($a, $b) => [$rank($a['name']), $a['name']] <=> [$rank($b['name']), $b['name']]);
    }
    return ['monsters' => $out, 'more' => $more];
}
