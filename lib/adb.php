<?php
declare(strict_types=1);

/**
 * Monster search from a local copy of Sly Flourish's 5e Artisanal Database (5eADB),
 * used instead of Notion when config has 'adb' => ['path' => ..., 'url' => ...].
 *
 * Reads the same files the 5eADB's own combat tracker uses:
 *   css_js/monster_combat_stats.js           every monster: name, AC, HP, init, CR, type, source, link
 *   monsters/custom/custom_monster_index.js  your own monsters, same format
 *   monsters/md/...                          Markdown stat blocks (only read to spot legendary monsters)
 * The merged list is cached in data/adb-monsters.json and rebuilt when any of those files change.
 */
require_once __DIR__ . '/notion.php'; // app_config(), format_cr()

const ADB_CACHE = __DIR__ . '/../data/adb-monsters.json';

final class AdbError extends RuntimeException {}

function adb_config(): ?array
{
    // Setup checks a folder before it is saved.
    if (!empty($GLOBALS['ADB_PATH_OVERRIDE'])) {
        return ['path' => $GLOBALS['ADB_PATH_OVERRIDE']];
    }
    $a = app_config()['adb'] ?? null;
    return is_array($a) && !empty($a['path']) ? $a : null;
}

function adb_configured(): bool
{
    return adb_config() !== null;
}

/** Is this folder a 5eADB? Returns its real path, or throws with a reason. */
function adb_check_folder(string $path): string
{
    $path = trim($path);
    if (str_starts_with($path, '~/') && ($home = getenv('HOME') ?: ($_SERVER['HOME'] ?? ''))) {
        $path = rtrim($home, '/') . substr($path, 1);
    }
    $real = $path !== '' ? realpath($path) : false;
    if ($real === false || !is_dir($real)) {
        throw new AdbError("There's no folder at “{$path}”");
    }
    if (!is_file("$real/css_js/monster_combat_stats.js")) {
        $inner = glob("$real/*/css_js/monster_combat_stats.js") ?: [];
        if (count($inner) === 1) {
            return dirname($inner[0], 2); // they picked the folder the zip unpacked into
        }
        throw new AdbError("“{$real}” doesn't look like the 5e Artisanal Database (no css_js/monster_combat_stats.js inside)");
    }
    if (!is_readable("$real/css_js/monster_combat_stats.js")) {
        throw new AdbError("Encounter Manager isn't allowed to read “{$real}”");
    }
    return $real;
}

/** Likely 5eADB folders on this computer, to offer in Setup. */
function adb_find_folders(): array
{
    $home = rtrim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')), '/');
    $app = dirname(__DIR__);
    $roots = array_unique(array_filter([dirname($app), dirname($app, 2), dirname($app, 3),
        $home ? "$home/Sites" : null, $home ? "$home/Downloads" : null, $home ? "$home/Documents" : null,
        $home ? "$home/Desktop" : null, $home ?: null]));
    $found = [];
    foreach ($roots as $r) {
        foreach (array_merge(glob("$r/*/css_js/monster_combat_stats.js") ?: [], glob("$r/*/*/css_js/monster_combat_stats.js") ?: []) as $f) {
            $found[realpath(dirname($f, 2))] = true;
        }
    }
    return array_keys($found);
}

/** The JSON array inside "const x = [ ... ];" or "window.x = [ ... ];". Tolerates trailing commas. */
function adb_js_array(string $file): array
{
    $js = @file_get_contents($file);
    if ($js === false) {
        throw new AdbError('Could not read ' . $file);
    }
    $start = strpos($js, '[');
    $end = strrpos($js, ']');
    if ($start === false || $end === false || $end < $start) {
        throw new AdbError('No monster list found in ' . basename($file));
    }
    $json = preg_replace('~,(\s*[\]}])~', '$1', substr($js, $start, $end - $start + 1));
    $rows = json_decode($json, true);
    if (!is_array($rows)) {
        throw new AdbError('Could not read the monster list in ' . basename($file) . ' (' . json_last_error_msg() . ')');
    }
    return $rows;
}

/** Every monster in the 5eADB (cached), each ['name','cr','ac','hp','init','type','source','link','legendary']. */
function adb_monsters(): array
{
    $cfg = adb_config();
    if (!$cfg) {
        throw new AdbError('The 5e Artisanal Database is not set up');
    }
    $root = rtrim($cfg['path'], '/\\');
    $stats = "$root/css_js/monster_combat_stats.js";
    $custom = "$root/monsters/custom/custom_monster_index.js";
    if (!is_file($stats)) {
        throw new AdbError("No 5e Artisanal Database at $root (css_js/monster_combat_stats.js is missing)");
    }

    $stamp = 'v2|' . ($cfg['url'] ?? '') . '|' . implode('|', array_map(fn($f) => is_file($f) ? $f . '@' . filemtime($f) . ':' . filesize($f) : "$f@-", [$stats, $custom, "$root/monsters/monsters.json"]));
    static $mem = null;
    if ($mem && $mem['stamp'] === $stamp) {
        return $mem['monsters'];
    }
    $cached = is_file(ADB_CACHE) ? json_decode((string) file_get_contents(ADB_CACHE), true) : null;
    if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp) {
        $mem = $cached;
        return $cached['monsters'];
    }

    // Legendary = the stat block has a "Legendary Actions" section. The Markdown copy of each stat block
    // (monsters/md/..., or the custom .js file) is the complete one; monsters.json skips it for some books.
    $isLegendary = function (string $htmlLink) use ($root): bool {
        $rel = preg_replace('~^\./~', '', $htmlLink);
        if (preg_match('~^custom/index\.html\?file=([\w.-]+\.js)$~', $rel, $m)) {
            $file = "$root/monsters/custom/{$m[1]}";
        } elseif (preg_match('~^html/(.+)\.html$~', $rel, $m)) {
            $file = "$root/monsters/md/{$m[1]}.md";
        } else {
            return false;
        }
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        return (bool) preg_match('~^\s*#+\s*Legendary Actions~mi', $text);
    };

    // Stat block links: the 5eADB's own web address if one is set, else Encounter Manager serves it (adb.php).
    $base = rtrim((string) ($cfg['url'] ?? ''), '/') ?: 'adb.php';
    $out = [];
    $add = function (array $rows, bool $isCustom) use (&$out, $isLegendary, $base) {
        foreach ($rows as $r) {
            $name = trim((string) ($r['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rawLink = $link = (string) ($r['htmlLink'] ?? '');
            $rel = preg_replace('~^\./~', '', $rawLink);
            if ($link !== '') {
                $link = $base . '/monsters/' . preg_replace('~^\./~', '', $link);
            }
            $source = (string) ($r['source'] ?? '');
            $out[] = [
                'name'      => $name,
                'cr'        => is_numeric($r['cr'] ?? null) ? (float) $r['cr'] : null,
                'ac'        => is_numeric($r['ac'] ?? null) ? (int) $r['ac'] : null,
                'hp'        => (int) ($r['hp'] ?? 0),
                'init'      => (int) ($r['init'] ?? 0),
                'type'      => (string) ($r['type'] ?? ''),
                'source'    => $source . ($isCustom ? ' (custom)' : ''),
                'link'      => $link,
                'ref'       => $rel !== '' ? 'adb:' . $rel : null,
                'legendary' => $isLegendary($rawLink),
            ];
        }
    };
    $add(adb_js_array($stats), false);
    if (is_file($custom)) {
        try {
            $add(adb_js_array($custom), true);
        } catch (AdbError) {
            // A typo in the custom index shouldn't break searching everything else.
        }
    }

    $mem = ['stamp' => $stamp, 'monsters' => $out];
    @file_put_contents(ADB_CACHE, json_encode($mem));
    return $out;
}

/** Same shape as search_monsters() in notion.php. */
function adb_search_monsters(string $q, ?float $crMin = null, ?float $crMax = null, int $limit = 50): array
{
    $ql = mb_strtolower($q);
    $hits = [];
    foreach (adb_monsters() as $m) {
        if ($ql !== '' && !str_contains(mb_strtolower($m['name']), $ql)) {
            continue;
        }
        if (($crMin !== null || $crMax !== null)
            && ($m['cr'] === null || ($crMin !== null && $m['cr'] < $crMin) || ($crMax !== null && $m['cr'] > $crMax))) {
            continue;
        }
        $hits[] = $m;
    }
    // With a name: exact, then starts-with, then contains. Otherwise lowest CR first.
    $rank = fn($n) => $ql === '' ? 0 : (mb_strtolower($n) === $ql ? 0 : (str_starts_with(mb_strtolower($n), $ql) ? 1 : 2));
    usort($hits, fn($a, $b) => [$rank($a['name']), $a['cr'] ?? 99, $a['name'], $a['source']]
        <=> [$rank($b['name']), $b['cr'] ?? 99, $b['name'], $b['source']]);

    $out = [];
    foreach (array_slice($hits, 0, $limit) as $m) {
        $out[] = [
            'name'      => $m['name'],
            'cr'        => format_cr($m['cr']),
            'cr_value'  => $m['cr'],
            'ac'        => $m['ac'],
            'hp_max'    => $m['hp'],
            'init_mod'  => $m['init'],
            'type'      => $m['type'],
            'source'    => $m['source'],
            'legendary' => $m['legendary'],
            'link'      => $m['link'],
            'ref'       => $m['ref'] ?? null,
        ];
    }
    return ['monsters' => $out, 'more' => count($hits) > $limit];
}
