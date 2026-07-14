<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Http\Request;

// Maintenance mode (CLAUDE.md section 10): the flag only exists while the
// updater switches releases. Checked before bootstrap so it works even if
// the app is mid-switch; /admin stays reachable for the update step chain.
$maintenanceFlag = dirname(__DIR__, 2) . '/shared/maintenance.flag';
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_file($maintenanceFlag) && !str_starts_with($requestPath, '/admin')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 30');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Wartung</title></head>'
        . '<body><h1>Kurze Wartungspause</h1><p>Der Vereinskalender wird gerade aktualisiert. '
        . 'Bitte in einer Minute erneut laden.</p></body></html>';
    exit;
}

/** @var Kernel $kernel */
$kernel = require dirname(__DIR__) . '/app/src/bootstrap.php';
$kernel->handle(Request::fromGlobals())->send();
