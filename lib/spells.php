<?php
declare(strict_types=1);

/**
 * Spells from the 5e Artisanal Database, for the hover boxes on stat blocks.
 * Reads spells/spells.json (2014 SRD, 2024 SRD, Level Up, Deep Magic) and the custom spells in spells/custom,
 * and caches a compact copy in data/adb-spells.json, rebuilt when those files change.
 */
require_once __DIR__ . '/adb.php';

const ADB_SPELL_CACHE = __DIR__ . '/../data/adb-spells.json';

/** "Melf's Acid Arrow" → "melfs acid arrow". */
function spell_norm(string $name): string
{
    $s = mb_strtolower(trim($name));
    $s = str_replace(['’', "'", '‘'], '', $s);
    return trim((string) preg_replace('~[^a-z0-9]+~', ' ', $s));
}

/** All spells, grouped by normalized name: [norm => [ [source, name, level, …], … ]]. */
function adb_spells(): array
{
    $cfg = adb_config() ?? throw new AdbError('The 5e Artisanal Database is not set up');
    $root = rtrim($cfg['path'], '/\\');
    $json = "$root/spells/spells.json";
    $customIndex = "$root/spells/custom/custom_spells_index.js";
    $stamp = 'v1|' . implode('|', array_map(fn($f) => is_file($f) ? filemtime($f) . ':' . filesize($f) : '-', [$json, $customIndex]));

    static $mem = null;
    if ($mem && $mem['stamp'] === $stamp) {
        return $mem['spells'];
    }
    $cached = is_file(ADB_SPELL_CACHE) ? json_decode((string) file_get_contents(ADB_SPELL_CACHE), true) : null;
    if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp) {
        $mem = $cached;
        return $cached['spells'];
    }
    if (!is_file($json)) {
        throw new AdbError('No spells in this copy of the 5e Artisanal Database (spells/spells.json is missing)');
    }

    $yes = fn($v) => in_array(strtolower((string) $v), ['yes', 'true', '1'], true);
    $out = [];
    foreach (json_decode((string) file_get_contents($json), true) ?: [] as $s) {
        $name = trim((string) ($s['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $out[spell_norm($name)][] = [
            'name'          => $name,
            'source'        => (string) ($s['document__title'] ?? ''),
            'level'         => (int) ($s['level_int'] ?? $s['spell_level'] ?? 0),
            'school'        => (string) ($s['school'] ?? ''),
            'casting_time'  => (string) ($s['casting_time'] ?? ''),
            'range'         => (string) ($s['range'] ?? ''),
            'components'    => (string) ($s['components'] ?? ''),
            'material'      => (string) ($s['material'] ?? ''),
            'duration'      => (string) ($s['duration'] ?? ''),
            'concentration' => $yes($s['requires_concentration'] ?? $s['concentration'] ?? ''),
            'ritual'        => $yes($s['can_be_cast_as_ritual'] ?? $s['ritual'] ?? ''),
            'classes'       => (string) ($s['dnd_class'] ?? ''),
            'desc'          => trim((string) ($s['desc'] ?? '')),
            'higher_level'  => trim((string) ($s['higher_level'] ?? '')),
        ];
    }

    // Custom spells: an index plus one Markdown .js file each, like custom monsters.
    if (is_file($customIndex)) {
        try {
            foreach (adb_js_array($customIndex) as $c) {
                $name = trim((string) ($c['name'] ?? ''));
                if ($name === '' || !preg_match('~file=([\w.-]+\.js)~', (string) ($c['htmlLink'] ?? ''), $m)) {
                    continue;
                }
                $js = @file_get_contents("$root/spells/custom/{$m[1]}");
                $md = $js !== false && preg_match('~`(.*)`\s*;?\s*$~s', $js, $mm) ? str_replace(['\\`', '\\$'], ['`', '$'], $mm[1]) : '';
                $md = trim((string) preg_replace('~\A\s*#\s+[^\n]*\n~', '', $md));
                $out[spell_norm($name)][] = [
                    'name' => $name, 'source' => 'Custom', 'level' => (int) ($c['level'] ?? 0), 'school' => '',
                    'casting_time' => '', 'range' => '', 'components' => '', 'material' => '', 'duration' => '',
                    'concentration' => false, 'ritual' => false, 'classes' => (string) ($c['classes'] ?? ''),
                    'desc' => $md, 'higher_level' => '', 'markdown_only' => true,
                ];
            }
        } catch (AdbError) {
            // A typo in the custom index shouldn't hide every other spell.
        }
    }

    $mem = ['stamp' => $stamp, 'spells' => $out];
    @file_put_contents(ADB_SPELL_CACHE, json_encode($mem, JSON_UNESCAPED_UNICODE));
    return $out;
}

/** Spell names the hover boxes can find (normalized), for marking them in stat blocks. */
function spell_names(): array
{
    return array_keys(adb_spells());
}

/**
 * One spell, in the version that best fits the monster's book:
 * 2024 monsters get 2024 spells, Level Up monsters get Level Up spells, and so on.
 */
function spell_for(string $name, string $monsterSource = ''): ?array
{
    $versions = adb_spells()[spell_norm($name)] ?? null;
    if (!$versions) {
        return null;
    }
    $m = strtolower($monsterSource);
    $prefer = match (true) {
        str_contains($m, '5.2') || str_contains($m, '2025') || str_contains($m, '2024') => ['5.2 SRD', '5.1 SRD'],
        str_contains($m, 'a5e') || str_contains($m, 'level up')                         => ['Level Up', '5.1 SRD', '5.2 SRD'],
        str_contains($m, 'black flag')                                                   => ['5.2 SRD', '5.1 SRD'],
        str_contains($m, 'custom')                                                       => ['Custom', '5.2 SRD', '5.1 SRD'],
        default /* 2014 SRD, Tome of Beasts, Creature Codex */                           => ['5.1 SRD', 'Deep Magic', '5.2 SRD'],
    };
    $prefer = array_merge($prefer, ['5.2 SRD', '5.1 SRD', 'Deep Magic', 'Level Up', 'Custom']);
    foreach ($prefer as $p) {
        foreach ($versions as $v) {
            if (str_starts_with($v['source'], $p)) {
                return $v;
            }
        }
    }
    return $versions[0];
}
