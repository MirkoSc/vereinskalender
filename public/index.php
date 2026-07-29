<?php

declare(strict_types=1);

use App\Http\Kernel;
use App\Http\Request;

// Maintenance mode (CLAUDE.md sections 2/4/10): set while the updater
// switches releases AND for the whole duration of a projection rebuild.
// Checked before bootstrap so it works even if the app is mid-switch;
// /admin stays reachable for the update step chain.
//
// The docroot shim (ReleaseSwitcher::SHIM) performs the SAME check one
// level up, and that is the one that actually matters: between the two
// renames of a switch this file does not exist, so it cannot answer. This
// copy is kept because the shim only reaches an installation through the
// updater's self-healing - until that has run once, this is the only check
// there is.
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

// Bootstrap runs OUTSIDE the Kernel's own error handler, and things do fail
// there: the Kernel is built with view: $container->view(), which reads the
// app name from the DB, so an unreachable database - the single likeliest
// outage on shared hosting - throws before the Kernel exists. The result
// was a blank 500 with nothing written anywhere, which is exactly the blind
// spot shared/var/log/ is meant to close.
//
// Deliberately hand-rolled rather than using Support\FileLogger: the
// autoloader is what bootstrap.php loads first, so it may itself be the
// thing that failed. Same redaction rule as Http\Kernel - class, message,
// method, path, file, line, never the full exception string (a trace
// carries function arguments, and config credentials travel through here).
try {
    /** @var Kernel $kernel */
    $kernel = require dirname(__DIR__) . '/app/src/bootstrap.php';
    $kernel->handle(Request::fromGlobals())->send();
} catch (\Throwable $e) {
    $zeile = sprintf(
        '[%s] BOOTSTRAP %s: %s [%s %s] at %s:%d',
        date('c'),
        $e::class,
        $e->getMessage(),
        $_SERVER['REQUEST_METHOD'] ?? '-',
        $requestPath,
        $e->getFile(),
        $e->getLine(),
    );

    error_log($zeile);
    $logDatei = dirname(__DIR__, 2) . '/shared/var/log/app.log';
    if (is_dir(dirname($logDatei)) || @mkdir(dirname($logDatei), 0775, true) || is_dir(dirname($logDatei))) {
        @file_put_contents($logDatei, $zeile . "\n", FILE_APPEND | LOCK_EX);
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head>'
        . '<body><h1>Interner Fehler</h1><p>Der Vereinskalender ist gerade nicht erreichbar. '
        . 'Bitte später erneut versuchen.</p></body></html>';
}
