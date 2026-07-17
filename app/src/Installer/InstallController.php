<?php

declare(strict_types=1);

namespace App\Installer;

use App\Config\Paths;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Service\Migration\Migrator;
use App\Service\Migration\SqlSplitter;
use App\Service\Wappen\WappenService;
use App\View\View;

/**
 * /install - only reachable while shared/config.php is missing (the
 * bootstrap only registers these routes in install mode). Fresh install
 * runs all migrations from 0; restore imports an uploaded backup dump in
 * chunks (statement offset in the session, PHP time limits) and then
 * applies only migrations newer than the backup (CLAUDE.md section 10).
 */
final readonly class InstallController
{
    private const int STATEMENTS_PER_STEP = 200;

    public function __construct(
        private View $view,
        private Paths $paths,
    ) {
    }

    public function form(Request $request): ResponseInterface
    {
        session_start();

        return $this->render(['errors' => [], 'values' => []]);
    }

    public function submit(Request $request): ResponseInterface
    {
        session_start();

        $values = [
            'db_host' => trim((string) ($request->post['db_host'] ?? 'localhost')),
            'db_port' => trim((string) ($request->post['db_port'] ?? '3306')),
            'db_name' => trim((string) ($request->post['db_name'] ?? '')),
            'db_user' => trim((string) ($request->post['db_user'] ?? '')),
            'db_password' => (string) ($request->post['db_password'] ?? ''),
            'admin_username' => trim((string) ($request->post['admin_username'] ?? '')),
            'admin_password' => (string) ($request->post['admin_password'] ?? ''),
            'modus' => (string) ($request->post['modus'] ?? 'frisch'),
        ];

        $errors = [];
        if ($values['db_host'] === '' || $values['db_name'] === '' || $values['db_user'] === '') {
            $errors['db'] = 'Bitte Host, Datenbankname und Benutzer angeben.';
        }
        if (mb_strlen($values['admin_username']) < 3) {
            $errors['admin_username'] = 'Bootstrap-Benutzername: mindestens 3 Zeichen.';
        }
        if (strlen($values['admin_password']) < 12) {
            $errors['admin_password'] = 'Bootstrap-Passwort: mindestens 12 Zeichen.';
        }

        $pdo = null;
        if ($errors === []) {
            try {
                $pdo = $this->connect($values);
            } catch (\PDOException $e) {
                $errors['db'] = 'Verbindung fehlgeschlagen: ' . $e->getMessage();
            }
        }

        if ($values['modus'] === 'restore' && $errors === []) {
            $upload = $_FILES['backup'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
                $errors['backup'] = 'Bitte ein Backup-ZIP hochladen.';
            }
        }

        if ($errors !== []) {
            return $this->render(['errors' => $errors, 'values' => $values], 422);
        }
        assert($pdo instanceof \PDO);

        if ($values['modus'] === 'restore') {
            return $this->startRestore($values, (string) $_FILES['backup']['tmp_name']);
        }

        // fresh install: all migrations from 0, then write the config
        new Migrator($pdo, $this->paths->migrationsDir())->migrate();
        $this->finalize($values);

        return $this->render(['fertig' => true, 'errors' => [], 'values' => []]);
    }

    /**
     * Called by the restore page's JS until {"fertig": true}.
     */
    public function restoreStep(Request $request): ResponseInterface
    {
        session_start();

        $install = $_SESSION['install'] ?? null;
        if (!is_array($install)) {
            return Response::json(['fehler' => 'Keine Wiederherstellung aktiv.'], 409);
        }

        try {
            $pdo = $this->connect($install['values']);
            $dump = file_get_contents((string) $install['dump_file']);
            if ($dump === false) {
                throw new \RuntimeException('dump.sql nicht lesbar.');
            }

            $statements = SqlSplitter::split($dump);
            $offset = (int) $install['offset'];
            $chunk = array_slice($statements, $offset, self::STATEMENTS_PER_STEP);

            foreach ($chunk as $statement) {
                $pdo->exec($statement);
            }

            $offset += count($chunk);
            $_SESSION['install']['offset'] = $offset;

            if ($offset < count($statements)) {
                return Response::json(['fertig' => false, 'offset' => $offset, 'gesamt' => count($statements)]);
            }

            // dump imported: only migrations newer than the backup remain
            $applied = new Migrator($pdo, $this->paths->migrationsDir())->migrate();
            $this->finalize($install['values']);
            unlink((string) $install['dump_file']);
            unset($_SESSION['install']);

            return Response::json([
                'fertig' => true,
                'migrationen' => count($applied->applied),
                'weiter' => '/admin/login',
            ]);
        } catch (\Throwable $e) {
            return Response::json(['fehler' => $e->getMessage()], 500);
        }
    }

    /**
     * @param array<string, string> $values
     */
    private function startRestore(array $values, string $uploadedZip): ResponseInterface
    {
        $varDir = $this->paths->sharedDir() . '/var';
        if (!is_dir($varDir)) {
            mkdir($varDir, 0775, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($uploadedZip) !== true || $zip->locateName('dump.sql') === false) {
            return $this->render([
                'errors' => ['backup' => 'Das ZIP ist kein gültiges Backup (dump.sql fehlt).'],
                'values' => $values,
            ], 422);
        }

        $dumpFile = $varDir . '/install_restore_dump.sql';
        file_put_contents($dumpFile, $zip->getFromName('dump.sql'));
        new WappenService($this->paths->wappenDir())->restoreFromZip($zip);
        $zip->close();

        $_SESSION['install'] = [
            'values' => $values,
            'dump_file' => $dumpFile,
            'offset' => 0,
        ];

        return $this->render(['restore' => true, 'errors' => [], 'values' => []]);
    }

    /**
     * @param array<string, string> $values
     */
    private function connect(array $values): \PDO
    {
        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $values['db_host'],
                (int) ($values['db_port'] ?: 3306),
                $values['db_name'],
            ),
            $values['db_user'],
            $values['db_password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }

    /**
     * Writing the config locks the installer; afterwards setup.php removes
     * itself (best effort - it may already be gone).
     *
     * @param array<string, string> $values
     */
    private function finalize(array $values): void
    {
        ConfigWriter::write(
            $this->paths->configFile(),
            [
                'host' => $values['db_host'],
                'port' => (int) ($values['db_port'] ?: 3306),
                'name' => $values['db_name'],
                'user' => $values['db_user'],
                'password' => $values['db_password'],
            ],
            $values['admin_username'],
            $values['admin_password'],
        );

        $setupFile = dirname($this->paths->releaseRoot) . '/web/setup.php';
        if (is_file($setupFile)) {
            @unlink($setupFile);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data, int $status = 200): Response
    {
        return Response::html(
            $this->view->render('install', ['title' => 'Installation', ...$data]),
            $status,
        );
    }
}
