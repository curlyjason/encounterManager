<?php
declare(strict_types=1);

/**
 * Stat blocks for the stat block views. Sources, best first:
 *  - "adb:" refs: the 5e Artisanal Database's Markdown stat block (complete for every book), shown as-is.
 *  - "notion:" refs: the numbers from the Notion monster database, plus a link to the page.
 *  - anything else: the numbers the combatant has in the encounter.
 */
require_once __DIR__ . '/adb.php';
require_once __DIR__ . '/images.php';

const CREATURE_TYPES = ['aberration', 'beast', 'celestial', 'construct', 'dragon', 'elemental', 'fey', 'fiend',
    'giant', 'humanoid', 'monstrosity', 'ooze', 'plant', 'undead'];

/** Icon file for a type string like "Undead", "Humanoid (Goblinoid)" or "Swarm of Tiny Beasts". */
function type_icon(string $type): string
{
    $t = strtolower($type);
    if (str_contains($t, 'swarm')) {
        return 'assets/types/swarm.svg';
    }
    foreach (CREATURE_TYPES as $k) {
        if (str_contains($t, $k)) {
            return "assets/types/$k.svg";
        }
    }
    return 'assets/types/unknown.svg';
}

/** The 5eADB record for an "adb:" ref, or null. */
function adb_by_ref(string $ref): ?array
{
    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (adb_monsters() as $m) {
            if (!empty($m['ref'])) {
                $index[$m['ref']] = $m;
            }
        }
    }
    return $index[$ref] ?? null;
}

/** Best-guess ref for a combatant added before stat blocks existed: same name, same book if we can tell. */
function guess_ref(array $c): ?string
{
    if (!adb_configured() || ($c['kind'] ?? '') === 'pc') {
        return null;
    }
    $name = mb_strtolower(trim((string) preg_replace('~\s+\d+$~', '', (string) $c['name'])));
    $parts = array_map('trim', explode('·', (string) ($c['notes'] ?? '')));
    $source = mb_strtolower(preg_replace('~\s+\(custom\)$~', '', $parts[2] ?? ''));
    $best = null;
    foreach (adb_monsters() as $m) {
        if (mb_strtolower($m['name']) !== $name || empty($m['ref'])) {
            continue;
        }
        $src = mb_strtolower(preg_replace('~\s+\(custom\)$~', '', $m['source']));
        if ($source !== '' && ($src === $source || str_starts_with($src, $source))) {
            return $m['ref'];
        }
        $best ??= $m['ref'];
    }
    return $best;
}

/** Markdown for an "adb:" ref, with the top "# Name" heading taken off (the view shows its own header). */
function adb_markdown(string $ref): ?string
{
    $cfg = adb_config();
    if (!$cfg) {
        return null;
    }
    $root = realpath($cfg['path']);
    $rel = substr($ref, 4);
    if ($root === false) {
        return null;
    }
    if (preg_match('~^custom/index\.html\?file=([\w.-]+\.js)$~', $rel, $m)) {
        $js = @file_get_contents("$root/monsters/custom/{$m[1]}");
        if ($js === false || !preg_match('~`(.*)`\s*;?\s*$~s', $js, $mm)) {
            return null;
        }
        $md = str_replace(['\\`', '\\$'], ['`', '$'], $mm[1]);
    } elseif (preg_match('~^html/(.+)\.html$~', $rel, $m)) {
        $file = realpath("$root/monsters/md/{$m[1]}.md");
        if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $md = (string) file_get_contents($file);
    } else {
        return null;
    }
    return trim((string) preg_replace('~\A\s*#\s+[^\n]*\n~', '', $md));
}

/**
 * Everything a stat block view shows.
 * $c: the combatant row (optional). $ref: a monster ref (from search results, or the combatant's own).
 */
function statblock_data(?array $c, ?string $ref): array
{
    $ref = $ref ?: ($c['ref'] ?? null);
    $out = [
        'ref' => $ref, 'name' => $c ? preg_replace('~\s+\d+$~', '', (string) $c['name']) : '', 'type' => '', 'source' => '',
        'cr' => $c && $c['cr'] !== null ? format_cr((float) $c['cr']) : null, 'ac' => $c['ac'] ?? null,
        'hp' => $c['hp_max'] ?? null, 'init' => $c['init_mod'] ?? null, 'markdown' => null, 'link' => null,
        'legendary' => false, 'origin' => 'encounter',
    ];
    if ($c && !$ref) {
        $parts = array_map('trim', explode('·', (string) ($c['notes'] ?? '')));
        if (count($parts) >= 3 && str_starts_with($parts[0], 'CR ')) {
            $out['type'] = $parts[1];
            $out['source'] = $parts[2];
        }
    }

    if ($ref && str_starts_with($ref, 'adb:') && adb_configured()) {
        $m = adb_by_ref($ref);
        if ($m) {
            $out = array_merge($out, [
                'name' => $m['name'], 'type' => $m['type'], 'source' => $m['source'], 'cr' => format_cr($m['cr']),
                'ac' => $m['ac'], 'hp' => $m['hp'], 'init' => $m['init'], 'link' => $m['link'],
                'legendary' => $m['legendary'], 'origin' => 'adb',
            ]);
            $out['markdown'] = adb_markdown($ref);
        }
    } elseif ($ref && str_starts_with($ref, 'notion:') && notion_configured() && !empty(app_config()['monsters'])) {
        try {
            $p = notion_request('GET', 'pages/' . substr($ref, 7), null, '2025-09-03');
            $src = app_config()['monsters'];
            $f = monster_fields();
            $cr = np_num($p, col($src, $f, 'cr'));
            $out = array_merge($out, [
                'name'   => np_text($p, col($src, $f, 'name') ?? 'Name') ?: $out['name'],
                'type'   => np_text($p, col($src, $f, 'type')),
                'source' => np_text($p, col($src, $f, 'source')),
                'cr'     => $cr === null ? $out['cr'] : format_cr($cr),
                'ac'     => ($v = np_num($p, col($src, $f, 'ac'))) === null ? $out['ac'] : (int) $v,
                'hp'     => ($v = np_num($p, col($src, $f, 'hp_max'))) === null ? $out['hp'] : (int) $v,
                'init'   => ($v = np_num($p, col($src, $f, 'init_mod'))) === null ? $out['init'] : (int) $v,
                'legendary' => !in_array(strtolower(np_text($p, col($src, $f, 'legendary'))), ['', 'no', 'false', '-'], true),
                'link'   => $p['url'] ?? null,
                'origin' => 'notion',
            ]);
        } catch (NotionError) {
            // Page gone or not shared any more: fall back to the encounter's numbers.
        }
    }

    $out['key'] = image_key($out['name']);
    $out['image'] = $out['key'] !== '' ? image_lookup($out['key']) : null;
    $out['type_icon'] = type_icon($out['type']);
    $out['find_art'] = 'https://www.google.com/search?tbm=isch&q=' . rawurlencode('"' . $out['name'] . '" D&D monster art');
    return $out;
}
