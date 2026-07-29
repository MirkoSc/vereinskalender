<?php
// Docroot shim - written by setup.php on a fresh install and kept up
// to date by the updater (UpdateService::finish()). Do not edit.
$basis = dirname(__DIR__);
$release = $basis . '/current/public/index.php';
$pfad = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Missing release = mid-switch or a crashed one; the flag = update or
// projection rebuild in progress. /admin stays reachable while the
// flag is set (the update step chain runs there), but not while the
// release itself is gone - there is nothing to serve it with.
if (!is_file($release)
    || (is_file($basis . '/shared/maintenance.flag') && !str_starts_with($pfad, '/admin'))) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 30');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Wartung</title></head>'
        . '<body><h1>Kurze Wartungspause</h1><p>Der Vereinskalender wird gerade aktualisiert. '
        . 'Bitte in einer Minute erneut laden.</p></body></html>';
    exit;
}

require $release;
