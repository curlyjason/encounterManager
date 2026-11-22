<?php
declare(strict_types=1);

/**
 * Update check and one-click install from GitHub Releases.
 * Never touches data/ (encounters) or lib/config*.php (settings + Notion key).
 */
require_once __DIR__ . '/version.php';

const UPDATE_CACHE = APP_ROOT . '/data/update-check.json';
const UPDATE_CACHE_SECONDS = 6 * 3600;
const UPDATE_MAX_BYTES = 25 * 1024 * 1024;
/** Paths an update must never write to (relative to the app folder). */
const UPDATE_PROTECTED = '~^(data/|lib/config(\.backup)?\.php|\.git/|dist/)~';

final class UpdateError extends RuntimeException {}

function http_get(string $url, ?string $saveTo = null): string
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER  => $saveTo === null,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        CURLOPT_TIMEOUT         => 60,
        CURLOPT_USERAGENT       => 'EncounterManager-Updater',
        CURLOPT_HTTPHEADER      => ['Accept: application/vnd.github+json'],
        CURLOPT_MAXFILESIZE     => UPDATE_MAX_BYTES,
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $opts[CURLOPT_PROTOCOLS_STR] = 'https';
        $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
    } else {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    $fh = null;
    if ($saveTo !== null) {
        $fh = fopen($saveTo, 'wb');
        if (!$fh) {
            throw new UpdateError('Could not write to the data/ folder');
        }
        $opts[CURLOPT_FILE] = $fh;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) {
        fclose($fh);
    }
    if ($res === false) {
        throw new UpdateError('Could not reach GitHub: ' . $err);
    }
    if ($code >= 400) {
        throw new UpdateError($code === 404 ? 'No releases found on GitHub yet' : "GitHub answered HTTP $code");
    }
    return $saveTo === null ? (string) $res : '';
}

/**
 * What's newer than this copy. Cached for a few hours so the app doesn't ask GitHub on every page load.
 * Returns ['current', 'latest', 'available', 'notes' => [[version, date, html]], 'zip_url', 'page_url', ...].
 */
function check_for_update(bool $force = false): array
{
    $current = app_version();
    $base = ['current' => $current, 'dev' => is_dev_copy(), 'repo' => UPDATE_REPO, 'available' => false];
    if (UPDATE_REPO === '') {
        return $base + ['error' => 'Update checks are not set up for this copy'];
    }

    $cached = is_file(UPDATE_CACHE) ? json_decode((string) file_get_contents(UPDATE_CACHE), true) : null;
    if (!$force && $cached && ($cached['current'] ?? '') === $current
        && time() - ($cached['checked_at'] ?? 0) < UPDATE_CACHE_SECONDS) {
        return $cached['result'];
    }

    try {
        $releases = json_decode(http_get('https://api.github.com/repos/' . UPDATE_REPO . '/releases?per_page=30'), true);
        if (!is_array($releases)) {
            throw new UpdateError('GitHub sent something unexpected');
        }
        $newer = [];
        $latest = null;
        foreach ($releases as $r) {
            if (!empty($r['draft']) || !empty($r['prerelease'])) {
                continue;
            }
            $v = ltrim((string) ($r['tag_name'] ?? ''), 'vV');
            if (!preg_match('~^\d+\.\d+\.\d+$~', $v)) {
                continue;
            }
            $zip = null;
            foreach ($r['assets'] ?? [] as $a) {
                if (preg_match('~^encounterManager-.*\.zip$~', $a['name'] ?? '')) {
                    $zip = $a['browser_download_url'];
                }
            }
            if (!$latest || version_compare($v, $latest['version'], '>')) {
                $latest = ['version' => $v, 'zip_url' => $zip, 'page_url' => $r['html_url'] ?? null];
            }
            if (version_compare($v, $current, '>')) {
                $newer[] = [
                    'version' => $v,
                    'date'    => substr((string) ($r['published_at'] ?? ''), 0, 10),
                    'html'    => md_to_html((string) ($r['body'] ?? '')),
                ];
            }
        }
        usort($newer, fn($a, $b) => version_compare($b['version'], $a['version']));
        $result = array_merge($base, [
            'latest'    => $latest['version'] ?? $current,
            'available' => $latest && version_compare($latest['version'], $current, '>'),
            'notes'     => $newer,
            'zip_url'   => $latest['zip_url'] ?? null,
            'page_url'  => $latest['page_url'] ?? ('https://github.com/' . UPDATE_REPO . '/releases'),
            'checked'   => date('c'),
        ]);
    } catch (UpdateError $e) {
        return $base + ['error' => $e->getMessage()];
    }
    @file_put_contents(UPDATE_CACHE, json_encode(['current' => $current, 'checked_at' => time(), 'result' => $result]));
    return $result;
}

/** Every app file (relative path => absolute path), skipping data, settings, git and builds. */
function app_files(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (!preg_match(UPDATE_PROTECTED, $rel) && !preg_match('~(^|/)\.(DS_Store|idea/)~', $rel)) {
            $out[$rel] = $f->getPathname();
        }
    }
    return $out;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * Download the newest release, back up the current files, and swap in the new ones.
 * Returns ['from', 'to', 'backup'].
 */
function install_update(): array
{
    if (is_dev_copy()) {
        throw new UpdateError('This is the development copy (it has a .git folder). Update it with git instead.');
    }
    if (!class_exists('ZipArchive')) {
        throw new UpdateError('PHP’s zip extension is missing. Download the update from GitHub and unzip it over this folder instead.');
    }
    $root = realpath(APP_ROOT);
    if (!is_writable($root)) {
        throw new UpdateError('The app folder isn’t writable. Download the update from GitHub and unzip it over this folder instead.');
    }
    $info = check_for_update(true);
    if (!empty($info['error'])) {
        throw new UpdateError($info['error']);
    }
    if (!$info['available']) {
        throw new UpdateError('You already have the newest version (' . $info['current'] . ')');
    }
    if (empty($info['zip_url']) || !preg_match('~^https://github\.com/~', $info['zip_url'])) {
        throw new UpdateError('The newest release has no download attached');
    }

    $work = $root . '/data/updates';
    rrmdir($work);
    if (!@mkdir($work, 0700, true) && !is_dir($work)) {
        throw new UpdateError('Could not create data/updates');
    }
    $zipFile = "$work/download.zip";
    $staging = "$work/staging";

    try {
        // 1. Download and sanity-check the package.
        http_get($info['zip_url'], $zipFile);
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new UpdateError('The download isn’t a valid zip file');
        }
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (!str_starts_with($name, 'encounterManager/') || str_contains($name, '../') || str_contains($name, "\0")) {
                $zip->close();
                throw new UpdateError('The download has unexpected files in it; nothing was changed');
            }
            $rel = substr($name, strlen('encounterManager/'));
            if (!preg_match(UPDATE_PROTECTED, $rel)) {
                $files[$i] = $rel;
            }
        }
        $newVersion = trim((string) $zip->getFromName('encounterManager/VERSION'));
        if ($newVersion !== $info['latest']) {
            $zip->close();
            throw new UpdateError("The download says it's version “{$newVersion}”, expected {$info['latest']}; nothing was changed");
        }
        foreach ($files as $i => $rel) {
            $dest = "$staging/$rel";
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0755, true);
            }
            if (file_put_contents($dest, $zip->getFromIndex($i)) === false) {
                $zip->close();
                throw new UpdateError('Could not unpack the update');
            }
        }
        $zip->close();

        // 2. Back up the current version (app files only).
        $backupDir = $root . '/data/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0700, true);
        }
        $backup = sprintf('%s/encounterManager-%s-%s.zip', $backupDir, app_version(), date('Ymd-His'));
        $bz = new ZipArchive();
        if ($bz->open($backup, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateError('Could not create a backup; nothing was changed');
        }
        foreach (app_files($root) as $rel => $abs) {
            $bz->addFile($abs, $rel);
        }
        $bz->close();

        // 3. Swap in the new files. If anything fails part-way, put the backup back.
        $from = app_version();
        try {
            foreach (app_files($staging) as $rel => $abs) {
                $dest = "$root/$rel";
                if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0755, true)) {
                    throw new UpdateError("Could not create folder for $rel");
                }
                if (!copy($abs, $dest)) {
                    throw new UpdateError("Could not replace $rel");
                }
            }
        } catch (Throwable $e) {
            $rz = new ZipArchive();
            if ($rz->open($backup) === true) {
                $rz->extractTo($root);
                $rz->close();
            }
            throw new UpdateError('The update failed and the previous version was put back: ' . $e->getMessage());
        }
        foreach (['start.command'] as $exe) {
            @chmod("$root/$exe", 0755);
        }
        @unlink(UPDATE_CACHE);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        return ['from' => $from, 'to' => $newVersion, 'backup' => 'data/backups/' . basename($backup)];
    } finally {
        rrmdir($work);
    }
}
