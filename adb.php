<?php
declare(strict_types=1);

/**
 * Serves pages from the local 5e Artisanal Database folder (set in Setup), so stat block links work
 * without a web server of its own:  adb.php/monsters/html/5.2_srd_(2025_mm)/lich_mm_2024.html
 * Read-only, static file types only, and never outside that folder.
 */
require __DIR__ . '/lib/adb.php';

const ADB_TYPES = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
    'json' => 'application/json', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'pdf' => 'application/pdf',
];

function adb_404(string $msg): never
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

$cfg = adb_config();
if (!$cfg) {
    adb_404('The 5e Artisanal Database is not set up. Add its folder in Setup.');
}
$root = realpath($cfg['path']);
$info = (string) ($_SERVER['PATH_INFO'] ?? '');
$rel = ltrim(rawurldecode($info), '/');
if ($root === false) {
    adb_404('The 5e Artisanal Database folder is missing. Check it in Setup.');
}
if ($rel === '') {
    header('Location: ' . ($info === '' ? basename(__FILE__) . '/' : '') . 'index.html');
    exit;
}
$file = realpath("$root/$rel");
if (is_dir((string) $file)) {
    $file = realpath("$file/index.html");
}
$ext = strtolower(pathinfo((string) $file, PATHINFO_EXTENSION));
if ($file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file)
    || !isset(ADB_TYPES[$ext]) || preg_match('~/\.~', substr($file, strlen($root)))) {
    adb_404('Not found');
}
header('Content-Type: ' . ADB_TYPES[$ext]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: max-age=3600');
readfile($file);
