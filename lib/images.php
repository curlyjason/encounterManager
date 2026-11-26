<?php
declare(strict_types=1);

/**
 * Monster images.
 *
 * The shared collection lives in the GitHub repo at images/monsters/ (one <key>.webp per monster name, plus
 * index.json with each image's credit and license). Every copy of the app reads that collection from GitHub
 * and keeps local copies in data/image-cache/, so new images reach everyone as soon as they're pushed,
 * without a release.
 *
 * Adding an image:
 *  - The development copy (has .git) writes shareable images straight into images/monsters/, ready to commit.
 *  - Other copies keep them in data/my-images/ and can export them as a zip ("Share my images") for the
 *    maintainer to import.
 *  - "Personal use only" images stay in data/my-images/ in every copy and are never shared or committed.
 * Everything is resized to at most 512 px and saved as WebP.
 */
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/updater.php'; // http_get(), UpdateError

const IMG_REPO_DIR = APP_ROOT . '/images/monsters';
const IMG_MINE_DIR = APP_ROOT . '/data/my-images';
const IMG_CACHE_DIR = APP_ROOT . '/data/image-cache';
const IMG_REMOTE_CACHE = APP_ROOT . '/data/image-manifest.json';
const IMG_REMOTE_SECONDS = 6 * 3600;
const IMG_MAX_SIDE = 512;
const IMG_MAX_INPUT = 15 * 1024 * 1024;
const IMG_LICENSES = [
    'own'      => ['label' => 'My own work', 'share' => true, 'credit' => false],
    'cc0'      => ['label' => 'CC0 / public domain', 'share' => true, 'credit' => false],
    'cc-by'    => ['label' => 'CC BY (credit the artist)', 'share' => true, 'credit' => true],
    'cc-by-sa' => ['label' => 'CC BY-SA (credit the artist)', 'share' => true, 'credit' => true],
    'ai'       => ['label' => 'AI-generated (I made it)', 'share' => true, 'credit' => false],
    'personal' => ['label' => 'Personal use only (never shared)', 'share' => false, 'credit' => false],
];

final class ImageError extends RuntimeException {}

/** File key for a monster name: "Adult Red Dragon" → "adult-red-dragon". */
function image_key(string $name): string
{
    $s = strtolower(trim($name));
    $s = str_replace(['&', "'", '’'], ['and', '', ''], $s);
    $s = trim((string) preg_replace('~[^a-z0-9]+~', '-', $s), '-');
    return substr($s, 0, 80);
}

function valid_image_key(string $key): bool
{
    return (bool) preg_match('~^[a-z0-9]+(-[a-z0-9]+)*$~', $key) && strlen($key) <= 80;
}

function images_can_edit(): bool
{
    return function_exists('imagewebp') && function_exists('imagecreatefromstring');
}

// ---- manifests -------------------------------------------------------------------

function manifest_read(string $file): array
{
    $m = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($m) && is_array($m['images'] ?? null) ? $m : ['images' => []];
}

function manifest_write(string $file, array $m): void
{
    if (is_array($m['images'])) {
        ksort($m['images']);
    }
    $m = ['about' => 'Encounter Manager monster images. Each entry: monster name, credit, license, source.'] + $m;
    $m['images'] = $m['images'] ?: new stdClass(); // keep it a JSON object when empty
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false
        || !@rename($tmp, $file)) {
        throw new ImageError('Could not save ' . basename(dirname($file)) . '/' . basename($file));
    }
}

/** The shared collection's list: the repo folder itself in the dev copy, otherwise GitHub's copy (cached). */
function shared_manifest(bool $force = false): array
{
    if (is_dev_copy() || UPDATE_REPO === '') {
        return manifest_read(IMG_REPO_DIR . '/index.json');
    }
    $cached = is_file(IMG_REMOTE_CACHE) ? json_decode((string) file_get_contents(IMG_REMOTE_CACHE), true) : null;
    if (!$force && is_array($cached) && time() - ($cached['checked_at'] ?? 0) < IMG_REMOTE_SECONDS) {
        return $cached['manifest'];
    }
    try {
        $m = json_decode(http_get(shared_base_url() . 'index.json'), true);
        $m = is_array($m) && is_array($m['images'] ?? null) ? $m : ['images' => []];
    } catch (UpdateError) {
        // Offline or not published yet: keep what we had, and try again later.
        $m = is_array($cached) ? $cached['manifest'] : ['images' => []];
    }
    @file_put_contents(IMG_REMOTE_CACHE, json_encode(['checked_at' => time(), 'manifest' => $m]));
    return $m;
}

function shared_base_url(): string
{
    return 'https://raw.githubusercontent.com/' . UPDATE_REPO . '/main/images/monsters/';
}

function mine_manifest(): array
{
    return manifest_read(IMG_MINE_DIR . '/index.json');
}

// ---- lookup ----------------------------------------------------------------------

/**
 * The image for a monster key, or null. Your own images win over the shared collection.
 * ['url', 'name', 'credit', 'license', 'license_label', 'source', 'where' => 'mine'|'shared', 'shared' => bool, 'pending' => bool]
 */
function image_lookup(string $key): ?array
{
    if (!valid_image_key($key)) {
        return null;
    }
    $mine = mine_manifest()['images'][$key] ?? null;
    $sharedList = shared_manifest()['images'];
    if ($mine && is_file(IMG_MINE_DIR . "/$key.webp")) {
        $meta = $mine + ['where' => 'mine', 'v' => filemtime(IMG_MINE_DIR . "/$key.webp")];
        $meta['pending'] = !empty($mine['shared']) && !isset($sharedList[$key]);
    } elseif (isset($sharedList[$key])) {
        $meta = $sharedList[$key] + ['where' => 'shared', 'shared' => true, 'pending' => false];
        $meta['v'] = substr(md5(json_encode($sharedList[$key])), 0, 8);
    } else {
        return null;
    }
    $meta['url'] = 'image.php?key=' . rawurlencode($key) . '&v=' . $meta['v'];
    $meta['license_label'] = IMG_LICENSES[$meta['license'] ?? '']['label'] ?? ($meta['license'] ?? '');
    $meta['key'] = $key;
    unset($meta['file']);
    return $meta;
}

/** Path to the image file for a key (downloads and caches shared images), or null. */
function image_file(string $key): ?string
{
    if (!valid_image_key($key)) {
        return null;
    }
    if (isset(mine_manifest()['images'][$key]) && is_file(IMG_MINE_DIR . "/$key.webp")) {
        return IMG_MINE_DIR . "/$key.webp";
    }
    if (is_file(IMG_REPO_DIR . "/$key.webp") && (is_dev_copy() || UPDATE_REPO === '')) {
        return IMG_REPO_DIR . "/$key.webp";
    }
    $entry = shared_manifest()['images'][$key] ?? null;
    if (!$entry) {
        return null;
    }
    $cached = IMG_CACHE_DIR . "/$key.webp";
    $stamp = IMG_CACHE_DIR . "/$key.json";
    $want = md5(json_encode($entry));
    if (is_file($cached) && is_file($stamp) && trim((string) file_get_contents($stamp)) === $want) {
        return $cached;
    }
    try {
        $bytes = http_get(shared_base_url() . rawurlencode($key) . '.webp');
    } catch (UpdateError) {
        return is_file($cached) ? $cached : null; // offline: an older copy is better than none
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || ($info['mime'] ?? '') !== 'image/webp' || strlen($bytes) > 2 * 1024 * 1024) {
        return null;
    }
    if (!is_dir(IMG_CACHE_DIR)) {
        @mkdir(IMG_CACHE_DIR, 0755, true);
    }
    @file_put_contents($cached, $bytes);
    @file_put_contents($stamp, $want);
    return is_file($cached) ? $cached : null;
}

// ---- adding ----------------------------------------------------------------------

/** Any image in → WebP out, longest side at most 512 px, metadata stripped. */
function image_process(string $bytes): string
{
    if (!images_can_edit()) {
        throw new ImageError('Adding images needs PHP’s GD extension with WebP support');
    }
    if (strlen($bytes) > IMG_MAX_INPUT) {
        throw new ImageError('That image is too big (over 15 MB)');
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp'], true)) {
        throw new ImageError('That isn’t an image this app can read (use JPG, PNG, WebP, GIF or AVIF)');
    }
    [$w, $h] = $info;
    if ($w < 16 || $h < 16 || $w * $h > 40_000_000) {
        throw new ImageError('That image is an odd size (' . $w . '×' . $h . ')');
    }
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        throw new ImageError('Could not read that image');
    }
    $scale = min(1, IMG_MAX_SIDE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);
    $out = '';
    foreach ([80, 70, 60] as $q) {
        ob_start();
        imagewebp($dst, null, $q);
        $out = (string) ob_get_clean();
        if (strlen($out) <= 80 * 1024) {
            break;
        }
    }
    imagedestroy($dst);
    if ($out === '') {
        throw new ImageError('Could not convert that image');
    }
    return $out;
}

/** Fetch an image from the web for "paste a web address". Refuses addresses on this computer or local network. */
function image_fetch_url(string $url): string
{
    for ($hop = 0; $hop < 4; $hop++) {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!$parts || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new ImageError('That doesn’t look like a web address (it should start with https://)');
        }
        $ips = gethostbynamel($parts['host']) ?: [];
        if (!$ips) {
            throw new ImageError('Couldn’t find ' . $parts['host']);
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new ImageError('Images can only come from public websites');
            }
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_FOLLOWLOCATION  => false, // each redirect is checked again above
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_USERAGENT       => 'Mozilla/5.0 (EncounterManager image fetch)',
            CURLOPT_MAXFILESIZE     => IMG_MAX_INPUT,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE         => [$parts['host'] . ':' . $port . ':' . $ips[0]], // the address we checked
        ]);
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        $bytes = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $err = curl_error($ch);
        curl_close($ch);
        if ($bytes !== false && $code >= 300 && $code < 400 && $next !== '') {
            $url = $next;
            continue;
        }
        if ($bytes === false || $code >= 400) {
            throw new ImageError('Couldn’t download that image' . ($code >= 400 ? " (the site answered $code)" : ": $err")
                . '. Try saving it and dropping the file in instead.');
        }
        return (string) $bytes;
    }
    throw new ImageError('That address redirects too many times');
}

/**
 * Save an image for a monster. $meta: name, license, credit, source.
 * Returns the new image_lookup() result.
 */
function image_save(string $key, string $bytes, array $meta, bool $alreadyProcessed = false): array
{
    if (!valid_image_key($key)) {
        throw new ImageError('Bad monster name for an image');
    }
    $license = (string) ($meta['license'] ?? '');
    if (!isset(IMG_LICENSES[$license])) {
        throw new ImageError('Say where the image comes from (pick a license)');
    }
    $credit = trim(mb_substr((string) ($meta['credit'] ?? ''), 0, 200));
    if (IMG_LICENSES[$license]['credit'] && $credit === '') {
        throw new ImageError('This license needs a credit: who made the image?');
    }
    $source = trim(mb_substr((string) ($meta['source'] ?? ''), 0, 500));
    if ($source !== '' && !preg_match('~^https?://~i', $source)) {
        $source = '';
    }
    $webp = $alreadyProcessed ? $bytes : image_process($bytes);
    $entry = [
        'name'    => trim(mb_substr((string) ($meta['name'] ?? $key), 0, 120)),
        'credit'  => $credit,
        'license' => $license,
        'source'  => $source,
        'added'   => preg_match('~^\d{4}-\d{2}-\d{2}$~', (string) ($meta['added'] ?? '')) ? $meta['added'] : date('Y-m-d'),
    ];
    $share = IMG_LICENSES[$license]['share'];

    if ($share && is_dev_copy()) {
        // Straight into the repo's shared collection.
        if (!is_dir(IMG_REPO_DIR) && !@mkdir(IMG_REPO_DIR, 0755, true)) {
            throw new ImageError('Could not create images/monsters');
        }
        if (@file_put_contents(IMG_REPO_DIR . "/$key.webp", $webp) === false) {
            throw new ImageError('Could not save the image in images/monsters');
        }
        $m = manifest_read(IMG_REPO_DIR . '/index.json');
        $m['images'][$key] = $entry;
        manifest_write(IMG_REPO_DIR . '/index.json', $m);
        image_forget_mine($key); // a shared image replaces a personal one
    } else {
        if (!is_dir(IMG_MINE_DIR) && !@mkdir(IMG_MINE_DIR, 0755, true)) {
            throw new ImageError('Could not create data/my-images');
        }
        if (@file_put_contents(IMG_MINE_DIR . "/$key.webp", $webp) === false) {
            throw new ImageError('Could not save the image in data/my-images');
        }
        $m = mine_manifest();
        $m['images'][$key] = $entry + ['shared' => $share];
        manifest_write(IMG_MINE_DIR . '/index.json', $m);
    }
    return image_lookup($key) ?? throw new ImageError('The image was saved but can’t be found');
}

function image_forget_mine(string $key): bool
{
    $m = mine_manifest();
    if (!isset($m['images'][$key])) {
        return false;
    }
    unset($m['images'][$key]);
    manifest_write(IMG_MINE_DIR . '/index.json', $m);
    @unlink(IMG_MINE_DIR . "/$key.webp");
    return true;
}

/** Remove your image for a monster. In the dev copy this can also remove it from the shared collection. */
function image_delete(string $key): void
{
    if (!valid_image_key($key)) {
        throw new ImageError('Bad monster name');
    }
    if (image_forget_mine($key)) {
        return;
    }
    if (is_dev_copy()) {
        $m = manifest_read(IMG_REPO_DIR . '/index.json');
        if (isset($m['images'][$key])) {
            unset($m['images'][$key]);
            manifest_write(IMG_REPO_DIR . '/index.json', $m);
            @unlink(IMG_REPO_DIR . "/$key.webp");
            return;
        }
    }
    throw new ImageError('Only your own images can be removed here');
}

// ---- sharing ---------------------------------------------------------------------

function images_summary(): array
{
    $mine = mine_manifest()['images'];
    $shared = shared_manifest()['images'];
    $toShare = array_filter($mine, fn($e, $k) => !empty($e['shared']) && !isset($shared[$k]), ARRAY_FILTER_USE_BOTH);
    return [
        'dev'        => is_dev_copy(),
        'can_edit'   => images_can_edit(),
        'shared'     => count($shared),
        'mine'       => count($mine),
        'personal'   => count(array_filter($mine, fn($e) => empty($e['shared']))),
        'to_share'   => count($toShare),
        'to_share_names' => array_values(array_map(fn($e) => $e['name'] ?? '', $toShare)),
        'licenses'   => array_map(fn($l) => ['label' => $l['label'], 'credit' => $l['credit'], 'share' => $l['share']], IMG_LICENSES),
        'repo'       => UPDATE_REPO,
    ];
}

/** Zip of your shareable images that aren't in the shared collection yet. Returns the zip's path. */
function images_export(): string
{
    $mine = mine_manifest()['images'];
    $shared = shared_manifest(true)['images'];
    $list = ['images' => []];
    $zipPath = APP_ROOT . '/data/encounter-manager-images-' . date('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new ImageError('Could not create the zip');
    }
    foreach ($mine as $key => $e) {
        if (empty($e['shared']) || isset($shared[$key]) || !is_file(IMG_MINE_DIR . "/$key.webp")) {
            continue;
        }
        $zip->addFile(IMG_MINE_DIR . "/$key.webp", "$key.webp");
        unset($e['shared']);
        $list['images'][$key] = $e;
    }
    if (!$list['images']) {
        $zip->close();
        @unlink($zipPath);
        throw new ImageError('There are no new images to share');
    }
    $zip->addFromString('index.json', json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->close();
    return $zipPath;
}

/**
 * Add images from a shared zip into the collection (dev copy) or into your images (other copies).
 * Every image is re-checked and re-converted. Existing images are kept unless $replace.
 */
function images_import(string $zipFile, bool $replace = false): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new ImageError('That isn’t a zip file');
    }
    $list = json_decode((string) $zip->getFromName('index.json'), true);
    if (!is_array($list['images'] ?? null)) {
        $zip->close();
        throw new ImageError('That zip has no index.json: it should come from “Share my images”');
    }
    $have = is_dev_copy() ? manifest_read(IMG_REPO_DIR . '/index.json')['images'] : mine_manifest()['images'];
    $res = ['added' => [], 'skipped' => [], 'failed' => []];
    foreach ($list['images'] as $key => $e) {
        $key = (string) $key;
        $name = (string) ($e['name'] ?? $key);
        if (!valid_image_key($key) || !(IMG_LICENSES[(string) ($e['license'] ?? '')]['share'] ?? false)) {
            $res['failed'][] = "$name (not shareable)";
            continue;
        }
        if (isset($have[$key]) && !$replace) {
            $res['skipped'][] = $name;
            continue;
        }
        $bytes = $zip->getFromName("$key.webp");
        if ($bytes === false || strlen($bytes) > IMG_MAX_INPUT) {
            $res['failed'][] = "$name (image missing)";
            continue;
        }
        try {
            image_save($key, $bytes, $e);
            $res['added'][] = $name;
        } catch (ImageError $ex) {
            $res['failed'][] = "$name (" . $ex->getMessage() . ')';
        }
    }
    $zip->close();
    return $res;
}

/**
 * Add a folder of images, matching file names to monster names ("Adult Red Dragon.png", "adult_red_dragon.jpg").
 * Works through the folder a batch at a time: call again with the returned 'next' until it's null.
 */
function images_import_folder(string $dir, array $known, array $meta, int $offset = 0, int $batch = 20, bool $replace = false): array
{
    // $known: monster key => monster name.
    $license = (string) ($meta['license'] ?? '');
    if (!isset(IMG_LICENSES[$license])) {
        throw new ImageError('Say where these images come from (pick a license)');
    }
    $real = realpath($dir);
    if ($real === false || !is_dir($real)) {
        throw new ImageError("There's no folder at “{$dir}”");
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && preg_match('~\.(jpe?g|png|webp|gif|avif)$~i', $f->getFilename())) {
            $files[] = $f->getPathname();
        }
        if (count($files) > 20000) {
            break;
        }
    }
    sort($files);
    $have = is_dev_copy() && IMG_LICENSES[$license]['share']
        ? manifest_read(IMG_REPO_DIR . '/index.json')['images'] : mine_manifest()['images'];
    $res = ['total' => count($files), 'added' => [], 'skipped' => [], 'unmatched' => [], 'failed' => []];
    foreach (array_slice($files, $offset, $batch) as $path) {
        $base = pathinfo($path, PATHINFO_FILENAME);
        // Token packs often add words like "token" or a size: try the full name, then trimmed versions.
        $tries = [image_key($base), image_key(preg_replace('~[\s_-]*(token|portrait|art|\d+px|\d+x\d+|v\d+|\(\d+\)|\d+)$~i', '', $base))];
        $key = null;
        foreach ($tries as $t) {
            if (isset($known[$t])) {
                $key = $t;
                break;
            }
        }
        if ($key === null) {
            $res['unmatched'][] = basename($path);
            continue;
        }
        if (isset($have[$key]) && !$replace) {
            $res['skipped'][] = basename($path);
            continue;
        }
        try {
            image_save($key, (string) file_get_contents($path), ['name' => $known[$key]] + $meta);
            $res['added'][] = basename($path);
            $have[$key] = true;
        } catch (ImageError $e) {
            $res['failed'][] = basename($path) . ' (' . $e->getMessage() . ')';
        }
    }
    $res['next'] = $offset + $batch < count($files) ? $offset + $batch : null;
    return $res;
}
