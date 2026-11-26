<?php
declare(strict_types=1);

/**
 * Generate monster art with OpenAI's image API, using the user's own API key (set in Setup).
 * Config: 'image_gen' => ['provider' => 'openai', 'api_key' => 'sk-…', 'model' => 'gpt-image-…', 'quality' => 'medium'].
 * The key never leaves the server and is never sent back to the browser.
 */
require_once __DIR__ . '/notion.php'; // app_config()
require_once __DIR__ . '/images.php'; // image_process(), ImageError

const GEN_QUALITIES = [
    'low'    => 'Low: quick sketches, about 1¢ each',
    'medium' => 'Medium: good for most monsters, about 5¢ each',
    'high'   => 'High: most detail, about 20¢ each',
];
const GEN_PRICING_URL = 'https://developers.openai.com/api/docs/pricing';

function gen_config(): ?array
{
    $g = app_config()['image_gen'] ?? null;
    return is_array($g) && trim((string) ($g['api_key'] ?? '')) !== '' ? $g : null;
}

function gen_public(): array
{
    $g = gen_config();
    return [
        'configured' => $g !== null,
        'model'      => $g['model'] ?? null,
        'quality'    => $g['quality'] ?? 'medium',
        'qualities'  => GEN_QUALITIES,
        'pricing'    => GEN_PRICING_URL,
    ];
}

/** Call the OpenAI API. Returns the decoded JSON or throws ImageError with OpenAI's own explanation. */
function openai_request(string $method, string $path, ?array $body, string $key, int $timeout = 30): array
{
    $ch = curl_init('https://api.openai.com/v1/' . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        throw new ImageError(str_contains($err, 'timed out')
            ? 'OpenAI took too long to answer. Try again, or pick a lower quality.'
            : 'Could not reach OpenAI: ' . $err);
    }
    $json = json_decode((string) $res, true);
    if ($code >= 400 || !is_array($json)) {
        $e = is_array($json) ? ($json['error'] ?? []) : [];
        $msg = trim((string) ($e['message'] ?? ''));
        $why = match (true) {
            ($e['code'] ?? '') === 'moderation_blocked' || str_contains($msg, 'safety system')
                => 'OpenAI declined to draw that (its safety filter). Try rewording the description.',
            $code === 401 => 'OpenAI didn’t accept that API key. Check it in Setup.',
            $code === 429 && str_contains(strtolower($msg), 'quota')
                => 'Your OpenAI account is out of credit. Add some at platform.openai.com → Billing.',
            $code === 429 => 'OpenAI says slow down (too many requests). Wait a moment and try again.',
            $code === 403 && str_contains(strtolower($msg), 'verif')
                => 'OpenAI needs your organization verified before it will use this image model: ' . $msg,
            default => 'OpenAI: ' . ($msg !== '' ? $msg : "HTTP $code"),
        };
        throw new ImageError($why);
    }
    return $json;
}

/** Image models this key can use, newest first (e.g. gpt-image-2.6-sunburst). */
function gen_models(string $key): array
{
    $list = openai_request('GET', 'models', null, $key)['data'] ?? [];
    $ids = [];
    foreach ($list as $m) {
        $id = (string) ($m['id'] ?? '');
        if (str_starts_with($id, 'gpt-image') || $id === 'chatgpt-image-latest') {
            $ids[] = $id;
        }
    }
    // Highest version first; plain names before dated snapshots ("…-2026-09-08").
    usort($ids, function ($a, $b) {
        $va = preg_match('~gpt-image-(\d+(?:\.\d+)?)~', $a, $m) ? (float) $m[1] : 0;
        $vb = preg_match('~gpt-image-(\d+(?:\.\d+)?)~', $b, $m) ? (float) $m[1] : 0;
        $da = (int) preg_match('~-\d{4}-\d{2}-\d{2}$~', $a);
        $db = (int) preg_match('~-\d{4}-\d{2}-\d{2}$~', $b);
        $ma = (int) str_contains($a, 'mini');
        $mb = (int) str_contains($b, 'mini');
        return [$vb, $da, $ma, $a] <=> [$va, $db, $mb, $b];
    });
    return $ids;
}

/**
 * Generate one picture. Returns ['data' => data:image/webp;base64,… (already 512 px), 'model' => …].
 * Nothing is saved: the person decides whether to keep it.
 */
function gen_image(string $prompt, ?string $quality = null): array
{
    $g = gen_config() ?? throw new ImageError('Add an OpenAI API key in Setup to generate art');
    $prompt = trim(mb_substr($prompt, 0, 2000));
    if (mb_strlen($prompt) < 10) {
        throw new ImageError('Describe the monster first');
    }
    $quality = isset(GEN_QUALITIES[$quality ?? '']) ? $quality : ($g['quality'] ?? 'medium');
    $model = (string) ($g['model'] ?? '');
    if ($model === '') {
        throw new ImageError('Pick an image model in Setup');
    }
    @set_time_limit(200);
    $res = openai_request('POST', 'images/generations', [
        'model'         => $model,
        'prompt'        => $prompt,
        'n'             => 1,
        'size'          => '1024x1024',
        'quality'       => $quality,
        'output_format' => 'webp',
    ], (string) $g['api_key'], 180);
    $b64 = $res['data'][0]['b64_json'] ?? null;
    if (!$b64) {
        throw new ImageError('OpenAI sent back no picture. Try again.');
    }
    $webp = image_process((string) base64_decode($b64));
    return ['data' => 'data:image/webp;base64,' . base64_encode($webp), 'model' => $model, 'quality' => $quality];
}
