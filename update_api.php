<?php
declare(strict_types=1);

/** JSON API for update checks and one-click installs. */
require __DIR__ . '/lib/setup.php';
require __DIR__ . '/lib/updater.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['error' => 'POST only'], 405);
}
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

try {
    switch ($in['action'] ?? '') {
        case 'check':
            reply(check_for_update(!empty($in['force'])));

        case 'install':
            if (!setup_allowed()) {
                reply(['error' => 'Updates can only be installed on the computer running Encounter Manager.'], 403);
            }
            @set_time_limit(180);
            reply(['ok' => true] + install_update());

        default:
            reply(['error' => 'Unknown action'], 400);
    }
} catch (UpdateError $e) {
    reply(['error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    reply(['error' => $e->getMessage()], 500);
}
