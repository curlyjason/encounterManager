<?php
declare(strict_types=1);

/** Serves a monster image by key: your own, the dev copy's repo folder, or the shared collection (cached). */
require __DIR__ . '/lib/images.php';

$key = (string) ($_GET['key'] ?? '');
$file = valid_image_key($key) ? image_file($key) : null;
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No image');
}
header('Content-Type: image/webp');
header('Content-Length: ' . filesize($file));
// The URL carries a version (?v=…), so browsers can keep it a long time.
header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=604800' : 'no-cache'));
readfile($file);
