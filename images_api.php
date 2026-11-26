<?php
declare(strict_types=1);

/**
 * Monster images: add, remove, share and import. Only from this computer (like Setup).
 *   POST JSON {action: summary | save | delete | refresh | import_folder}
 *   POST form-data action=import, zip=<file>        a zip made by "Share my images"
 *   GET  ?action=export                              download your new images as a zip
 */
require __DIR__ . '/lib/setup.php';   // setup_allowed()
require __DIR__ . '/lib/statblock.php';
require __DIR__ . '/lib/imagegen.php';

function img_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!setup_allowed()) {
    img_out(['error' => 'Images can only be changed on the computer running Encounter Manager'], 403);
}

/** Every monster name we know of (5e Artisanal Database), as key => name, for matching file names. */
function known_monsters(): array
{
    $out = [];
    if (adb_configured()) {
        foreach (adb_monsters() as $m) {
            $out[image_key($m['name'])] ??= $m['name'];
        }
    }
    return $out;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'export') {
        $zip = images_export();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip) . '"');
        header('Content-Length: ' . filesize($zip));
        readfile($zip);
        @unlink($zip);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        img_out(['error' => 'POST only'], 405);
    }
    if (($_POST['action'] ?? '') === 'import') {
        $f = $_FILES['zip'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            img_out(['error' => 'Pick the zip file first' . (($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? ' (it is bigger than PHP allows uploads to be)' : '')], 400);
        }
        img_out(images_import($f['tmp_name'], !empty($_POST['replace'])) + ['summary' => images_summary()]);
    }

    $in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    switch ($in['action'] ?? '') {
        case 'summary':
            img_out(images_summary() + ['known' => count(known_monsters()), 'gen' => gen_public()]);

        case 'list':
            // Everything with its credit, for the credits list on the Monster images page.
            $shared = shared_manifest()['images'];
            $rows = [];
            foreach (mine_manifest()['images'] as $k => $e) {
                $rows[$k] = $e + ['key' => $k, 'where' => empty($e['shared']) ? 'personal' : (isset($shared[$k]) ? 'shared' : 'to share')];
            }
            foreach ($shared as $k => $e) {
                $rows[$k] ??= $e + ['key' => $k, 'where' => 'shared'];
            }
            foreach ($rows as &$r) {
                $r['license_label'] = IMG_LICENSES[$r['license'] ?? '']['label'] ?? ($r['license'] ?? '');
                $r['url'] = 'image.php?key=' . rawurlencode($r['key']) . '&v=' . substr(md5(json_encode($r)), 0, 8);
                unset($r['shared'], $r['file']);
            }
            unset($r);
            uasort($rows, fn($a, $b) => strcasecmp($a['name'] ?? $a['key'], $b['name'] ?? $b['key']));
            img_out(['images' => array_values($rows)]);

        case 'refresh':
            shared_manifest(true);
            img_out(images_summary());

        case 'save':
            $key = (string) ($in['key'] ?? '');
            if (!empty($in['url'])) {
                $bytes = image_fetch_url((string) $in['url']);
                $in['source'] = ($in['source'] ?? '') ?: $in['url'];
            } elseif (preg_match('~^data:image/[\w.+-]+;base64,~', (string) ($in['data'] ?? ''), $m)) {
                $bytes = (string) base64_decode(substr($in['data'], strlen($m[0])), true);
            } else {
                img_out(['error' => 'Drop, paste or link an image first'], 400);
            }
            img_out(['image' => image_save($key, $bytes, $in)]);

        case 'generate':
            // Returns a preview only; saving is a separate 'save' once the person likes it.
            img_out(gen_image((string) ($in['prompt'] ?? ''), $in['quality'] ?? null));

        case 'delete':
            image_delete((string) ($in['key'] ?? ''));
            img_out(['ok' => true, 'image' => image_lookup((string) $in['key'])]);

        case 'import_folder':
            $known = known_monsters();
            if (!$known) {
                img_out(['error' => 'Folder import matches file names to monster names, so it needs the 5e Artisanal Database set up first'], 400);
            }
            img_out(images_import_folder((string) ($in['path'] ?? ''), $known,
                ['license' => $in['license'] ?? '', 'credit' => $in['credit'] ?? '', 'source' => $in['source'] ?? ''],
                max(0, (int) ($in['offset'] ?? 0)), 20, !empty($in['replace'])));

        default:
            img_out(['error' => 'Unknown action'], 400);
    }
} catch (ImageError | AdbError $e) {
    img_out(['error' => $e->getMessage()], 400);
}
