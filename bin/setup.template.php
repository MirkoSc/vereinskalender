<?php

/**
 * setup.php - Bootstrap installer (CLAUDE.md section 10, Nextcloud style).
 *
 * GENERATED FILE - edit bin/setup.template.php and the shared class
 * app/src/Service/Update/ReleaseDownloader.php, then run
 * `php bin/build_setup.php`. CI verifies freshness.
 *
 * Upload this single file via FTP into the /web docroot, open it in the
 * browser: environment checklist -> download + verify + unpack the newest
 * release -> create the web/ shim and shared/ -> redirect to /install.
 * It deletes itself once the installer has written the config.
 */

declare(strict_types=1);

namespace App\Service\Update {
//__SHARED_CODE__
}

namespace {

use App\Service\Update\ReleaseDownloader;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$webDir = __DIR__;
$rootDir = dirname(__DIR__);

/** @return list<array{label: string, ok: bool, detail: string}> */
function setup_checks(string $webDir, string $rootDir): array
{
    $checks = [];
    $checks[] = [
        'label' => 'PHP-Version ≥ 8.5',
        'ok' => version_compare(PHP_VERSION, '8.5.0', '>='),
        'detail' => 'gefunden: ' . PHP_VERSION,
    ];
    $checks[] = [
        'label' => 'ZipArchive verfügbar',
        'ok' => class_exists(\ZipArchive::class),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'PDO MySQL verfügbar',
        'ok' => extension_loaded('pdo_mysql'),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'GD verfügbar (für das Vereinswappen)',
        'ok' => extension_loaded('gd') && function_exists('imagecreatefrompng'),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'HTTPS-Downloads möglich (allow_url_fopen + openssl)',
        'ok' => ini_get('allow_url_fopen') === '1' && extension_loaded('openssl'),
        'detail' => '',
    ];
    $checks[] = [
        'label' => 'Schreibrechte oberhalb des DocumentRoot',
        'ok' => is_writable($rootDir),
        'detail' => $rootDir,
    ];
    $checks[] = [
        'label' => 'Schreibrechte im DocumentRoot',
        'ok' => is_writable($webDir),
        'detail' => $webDir,
    ];

    $renameOk = false;
    $probe = $rootDir . '/.setup_probe_' . getmypid();
    if (@file_put_contents($probe, 'x') !== false) {
        $renameOk = @rename($probe, $probe . '_renamed');
        @unlink($probe . '_renamed');
        @unlink($probe);
    }
    $checks[] = ['label' => 'rename() funktioniert', 'ok' => (bool) $renameOk, 'detail' => ''];

    return $checks;
}

/**
 * Heuristic against installing into the FTP root: in the intended layout
 * (domain docroot = a web/ SUBFOLDER of an otherwise empty directory) the
 * parent of setup.php contains nothing foreign. Entries besides the
 * docroot itself and our own directories indicate that setup.php probably
 * sits directly in the domain folder and the data would land in the
 * account root.
 *
 * @return list<string>
 */
function setup_fremde_eintraege(string $webDir, string $rootDir): array
{
    $eigene = [basename($webDir), 'current', 'releases', 'shared'];
    $fremd = [];
    foreach (scandir($rootDir) ?: [] as $eintrag) {
        if ($eintrag === '.' || $eintrag === '..' || in_array($eintrag, $eigene, true)) {
            continue;
        }
        $fremd[] = $eintrag;
    }

    return $fremd;
}

function setup_page(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
        // same Vereinsfarben-Palette as public/css/app.css (Issue #1); setup.php
        // ships as its own release asset and cannot link that stylesheet, so
        // its custom properties are kept in this single inline <style> block.
        . '<style>:root{--color-bg:#f4f6f4;--color-text:#131b15;--color-accent:#27683f;'
        . '--color-accent-bg:#328551;--color-on-accent:#ffffff;--color-danger:#a82d24}'
        . '@media (prefers-color-scheme: dark){:root{--color-bg:#131b15;--color-text:#e6eae7;'
        . '--color-accent:#95d0ab;--color-danger:#e0736c}}'
        . 'body{font-family:system-ui,sans-serif;max-width:40rem;margin:2rem auto;padding:0 1rem;'
        . 'line-height:1.5;background:var(--color-bg);color:var(--color-text)}'
        . 'li.ok::marker{content:"✔ ";color:var(--color-accent)}li.fehler::marker{content:"✘ ";color:var(--color-danger)}'
        . 'button{padding:.6rem 1.2rem;font:inherit;background:var(--color-accent-bg);color:var(--color-on-accent);border:none;border-radius:6px;cursor:pointer}'
        . '.fehlertext{color:var(--color-danger)}</style></head><body><h1>Vereinskalender einrichten</h1>'
        . $body . '</body></html>';
    exit;
}

// already installed and switched: hand over to the app installer
if (is_dir($rootDir . '/current')) {
    header('Location: /install');
    exit;
}

$fremdeEintraege = setup_fremde_eintraege($webDir, $rootDir);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $checks = setup_checks($webDir, $rootDir);
    $allOk = !in_array(false, array_column($checks, 'ok'), true);

    $items = '';
    foreach ($checks as $check) {
        $items .= '<li class="' . ($check['ok'] ? 'ok' : 'fehler') . '">'
            . htmlspecialchars($check['label'], ENT_QUOTES)
            . ($check['detail'] !== '' ? ' <small>(' . htmlspecialchars($check['detail'], ENT_QUOTES) . ')</small>' : '')
            . '</li>';
    }

    $layout = '<h2>Verzeichnisse</h2><p>So wird installiert:</p><ul>'
        . '<li><strong>DocumentRoot</strong> (dieses Verzeichnis): <code>' . htmlspecialchars($webDir, ENT_QUOTES) . '</code>'
        . ' – hier liegt nur setup.php und später der kleine index.php-Verweis.</li>'
        . '<li><strong>Datenverzeichnis</strong> (eine Ebene darüber, nicht per Browser erreichbar): <code>'
        . htmlspecialchars($rootDir, ENT_QUOTES) . '</code>'
        . ' – hier entstehen <code>current/</code>, <code>releases/</code> und <code>shared/</code>'
        . ' (Konfiguration, Backups).</li></ul>';

    $warnung = '';
    $bestaetigung = '';
    if ($fremdeEintraege !== []) {
        $liste = htmlspecialchars(implode(', ', array_slice($fremdeEintraege, 0, 8)), ENT_QUOTES)
            . (count($fremdeEintraege) > 8 ? ', …' : '');
        $warnung = '<p class="fehlertext"><strong>Achtung:</strong> Das Datenverzeichnis ist nicht leer'
            . ' (' . $liste . '). Vermutlich liegt setup.php direkt im Domain-Ordner und die Daten'
            . ' würden im FTP-Hauptverzeichnis landen. Empfohlen: im Domain-Ordner einen Unterordner'
            . ' <code>web</code> anlegen, die (Sub-)Domain im Kontrollpanel auf diesen <code>web</code>-Ordner zeigen'
            . ' lassen, setup.php dorthin verschieben und neu aufrufen.</p>';
        $bestaetigung = '<p><label><input type="checkbox" name="layout_bestaetigt" value="1"> '
            . 'Ich möchte trotzdem hier installieren – die Datenverzeichnisse sollen in '
            . '<code>' . htmlspecialchars($rootDir, ENT_QUOTES) . '</code> angelegt werden.</label></p>';
    }

    $form = $allOk
        ? '<form method="post"><p><label>Release-Kanal: <select name="kanal">'
            . '<option value="stable">stable (empfohlen)</option><option value="beta">beta (Pre-Releases, Testinstanz)</option>'
            . '</select></label></p>' . $bestaetigung . '<button type="submit">Installation starten</button></form>'
        : '<p class="fehlertext">Bitte zuerst die markierten Punkte beheben und die Seite neu laden.</p>';

    setup_page('Umgebungscheck', '<h2>Umgebungscheck</h2><ul>' . $items . '</ul>' . $layout . $warnung . $form);
}

// POST guard: with a suspicious layout the confirmation checkbox is required
if ($fremdeEintraege !== [] && ($_POST['layout_bestaetigt'] ?? '') !== '1') {
    setup_page('Bitte Struktur prüfen', '<p class="fehlertext">Installation nicht gestartet: Das Datenverzeichnis <code>'
        . htmlspecialchars($rootDir, ENT_QUOTES) . '</code> ist nicht leer. Bitte die empfohlene Struktur mit'
        . ' <code>web</code>-Unterordner einrichten – oder die Bestätigung auf der vorherigen Seite ankreuzen.</p>'
        . '<p><a href="setup.php">Zurück zum Umgebungscheck</a></p>');
}

// POST: download, verify, unpack, create layout, switch, redirect
try {
    $kanal = ($_POST['kanal'] ?? 'stable') === 'beta' ? 'beta' : 'stable';
    $downloader = new ReleaseDownloader();

    $release = $downloader->findLatestRelease($kanal);
    if ($release === null) {
        throw new RuntimeException('Kein Release auf GitHub gefunden (Kanal ' . $kanal . ').');
    }

    $releasesDir = $rootDir . '/releases';
    $sharedDir = $rootDir . '/shared';
    foreach ([$releasesDir, $sharedDir, $sharedDir . '/var', $sharedDir . '/var/log', $sharedDir . '/var/backups'] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    // leftover from a run of the buggy pre-v0.3.1 setup.php
    @unlink($releasesDir . '/download.zip');

    // the local name must stay the asset name - the checksum is matched
    // by filename against checksums.txt
    $zipFile = $releasesDir . '/' . ReleaseDownloader::zipFilename($release['zip_url']);
    $downloader->downloadTo($release['zip_url'], $zipFile);
    $downloader->verifyChecksum($zipFile, $downloader->fetchText($release['checksums_url']));

    $target = $releasesDir . '/v' . $release['version'];
    $downloader->extractTo($zipFile, $target);
    unlink($zipFile);

    // production shim - identical content to docker/web/index.php
    file_put_contents($webDir . '/index.php', "<?php require dirname(__DIR__).'/current/public/index.php';\n");
    if (!is_file($webDir . '/.htaccess')) {
        file_put_contents(
            $webDir . '/.htaccess',
            "Options -Indexes\nDirectoryIndex index.php\n\nRewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^ index.php [L]\n",
        );
    }

    if (!rename($target, $rootDir . '/current')) {
        throw new RuntimeException('Release kann nicht nach current/ verschoben werden.');
    }

    header('Location: /install');
    exit;
} catch (Throwable $e) {
    setup_page('Fehler', '<p class="fehlertext">Einrichtung fehlgeschlagen: '
        . htmlspecialchars($e->getMessage(), ENT_QUOTES)
        . '</p><p><a href="setup.php">Zurück zum Umgebungscheck</a></p>');
}

}
