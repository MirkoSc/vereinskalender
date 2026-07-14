<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Config\Paths;
use App\Repository\SettingRepository;
use App\Service\Backup\BackupService;
use App\Service\Migration\Migrator;
use App\Service\Stats\AlarmMailer;

/**
 * Update step chain (CLAUDE.md section 10): the admin UI calls each step
 * individually via AJAX, state lives in shared/update_state.json, every
 * step is idempotent and stays far below the PHP time limit. After the
 * 'switch' step the FOLLOWING requests already run on the new release -
 * these endpoints exist in every version from now on.
 */
final class UpdateService
{
    public function __construct(
        private readonly Paths $paths,
        private readonly string $currentVersion,
        private readonly SettingRepository $settings,
        private readonly BackupService $backups,
        private readonly ReleaseDownloader $downloader,
        private readonly ReleaseSwitcher $switcher,
        private readonly Migrator $migrator,
        private readonly ?AlarmMailer $alarmMailer = null,
    ) {
    }

    public function channel(): string
    {
        $kanal = $this->settings->get('update_kanal', 'stable');

        return $kanal === 'beta' ? 'beta' : 'stable';
    }

    public function setChannel(string $kanal): void
    {
        $this->settings->set('update_kanal', $kanal === 'beta' ? 'beta' : 'stable');
    }

    public function state(): ?UpdateState
    {
        $file = $this->stateFile();
        if (!is_file($file)) {
            return null;
        }
        $json = file_get_contents($file);
        if ($json === false || trim($json) === '') {
            return null;
        }

        return UpdateState::fromArray(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    public function reset(): void
    {
        $file = $this->stateFile();
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Step 1: query GitHub, compare versions, initialise the state.
     */
    public function check(): UpdateState
    {
        $release = $this->downloader->findLatestRelease($this->channel());

        if ($release === null || !version_compare($release['version'], $this->currentVersion, '>')) {
            $state = new UpdateState(
                aktuelleVersion: $this->currentVersion,
                fertig: true,
                meldungen: [$release === null
                    ? 'Kein Release gefunden.'
                    : sprintf('Version %s ist aktuell (neuestes Release: %s).', $this->currentVersion, $release['version'])],
            );
            $this->save($state);

            return $state;
        }

        $state = new UpdateState(
            aktuelleVersion: $this->currentVersion,
            zielVersion: $release['version'],
            zipUrl: $release['zip_url'],
            checksumsUrl: $release['checksums_url'],
            abgeschlossenerSchritt: 'check',
            meldungen: [sprintf('Update auf Version %s verfügbar (Kanal %s).', $release['version'], $this->channel())],
        );
        $this->save($state);

        return $state;
    }

    /**
     * Step 2: backup before anything else.
     */
    public function backup(): UpdateState
    {
        return $this->step('backup', function (UpdateState $state): UpdateState {
            $name = $this->backups->create();

            return $state->mit(meldung: 'Backup erstellt: ' . $name);
        });
    }

    /**
     * Step 3: download ZIP + checksums.txt, verify SHA-256.
     */
    public function download(): UpdateState
    {
        return $this->step('download', function (UpdateState $state): UpdateState {
            $zipFile = $this->zipFile((string) $state->zielVersion);
            $this->downloader->downloadTo((string) $state->zipUrl, $zipFile);
            $this->downloader->verifyChecksum($zipFile, $this->downloader->fetchText((string) $state->checksumsUrl));

            return $state->mit(meldung: 'Release-ZIP geladen und Prüfsumme bestätigt.');
        });
    }

    /**
     * Step 4: unpack into releases/vX.Y.Z (the running version is untouched).
     */
    public function extract(): UpdateState
    {
        return $this->step('extract', function (UpdateState $state): UpdateState {
            $version = (string) $state->zielVersion;
            $target = dirname($this->paths->releaseRoot) . '/releases/v' . $version;
            $this->downloader->extractTo($this->zipFile($version), $target);

            $versionFile = $target . '/VERSION';
            if (!is_file($versionFile) || trim((string) file_get_contents($versionFile)) !== $version) {
                throw new \RuntimeException('Entpacktes Release enthält keine passende VERSION-Datei.');
            }

            return $state->mit(meldung: 'Release entpackt nach releases/v' . $version . '.');
        });
    }

    /**
     * Step 5: atomic switch (maintenance flag around the renames).
     */
    public function switchRelease(): UpdateState
    {
        return $this->step('switch', function (UpdateState $state): UpdateState {
            $this->switcher->switchTo((string) $state->zielVersion);

            return $state->mit(meldung: 'Auf Version ' . $state->zielVersion . ' umgeschaltet.');
        });
    }

    /**
     * Step 6: apply pending migrations (already running on the new code).
     */
    public function migrate(): UpdateState
    {
        return $this->step('migrate', function (UpdateState $state): UpdateState {
            $result = $this->migrator->migrate();

            return $state->mit(meldung: $result->applied === []
                ? 'Keine Migrationen anzuwenden.'
                : sprintf('%d Migration(en) angewendet, Schema %d → %d.', count($result->applied), $result->fromVersion, $result->toVersion));
        });
    }

    /**
     * Step 7: self-test + cleanup, keep the last 2 releases.
     */
    public function finish(string $baseUrl): UpdateState
    {
        return $this->step('finish', function (UpdateState $state) use ($baseUrl): UpdateState {
            $this->selfTest($baseUrl);
            $this->switcher->cleanupOldReleases();

            $zipFile = $this->zipFile((string) $state->zielVersion);
            if (is_file($zipFile)) {
                unlink($zipFile);
            }

            return $state->mit(meldung: 'Selbsttest bestanden, alte Releases aufgeräumt.', fertig: true);
        });
    }

    public function rollback(): UpdateState
    {
        $state = $this->state() ?? new UpdateState(aktuelleVersion: $this->currentVersion);

        try {
            $this->switcher->rollback();
            $state = $state->mit(meldung: 'Rollback durchgeführt – vorheriges Release ist wieder aktiv.', fertig: true);
        } catch (\Throwable $e) {
            $state = $state->mit(fehler: $e->getMessage());
        }

        $this->save($state);

        return $state;
    }

    private function selfTest(string $baseUrl): void
    {
        $von = new \DateTimeImmutable('today')->format('Y-m-d');
        $bis = new \DateTimeImmutable('today +7 days')->format('Y-m-d');

        foreach (['/', '/api/events?von=' . $von . '&bis=' . $bis] as $path) {
            try {
                $this->downloader->fetchText(rtrim($baseUrl, '/') . $path);
            } catch (\RuntimeException $e) {
                throw new \RuntimeException('Selbsttest fehlgeschlagen für ' . $path . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * @param \Closure(UpdateState): UpdateState $work
     */
    private function step(string $schritt, \Closure $work): UpdateState
    {
        $state = $this->state();
        if ($state === null || $state->zielVersion === null) {
            throw new \RuntimeException('Kein Update vorbereitet – zuerst den Versionscheck ausführen.');
        }

        try {
            $state = $work($state)->mit(abgeschlossenerSchritt: $schritt);
        } catch (\Throwable $e) {
            $state = $state->mit(fehler: $e->getMessage());
            $this->alarmMailer?->alert(
                'updatefehler',
                'Update-Schritt fehlgeschlagen',
                sprintf("Schritt '%s' beim Update auf %s:\n\n%s\n", $schritt, (string) $state->zielVersion, $e->getMessage()),
            );
        }

        $this->save($state);

        return $state;
    }

    private function save(UpdateState $state): void
    {
        $file = $this->stateFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            $file,
            json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );
    }

    private function stateFile(): string
    {
        return $this->paths->sharedDir() . '/update_state.json';
    }

    private function zipFile(string $version): string
    {
        return $this->paths->sharedDir() . '/var/update/vereinskalender-v' . $version . '.zip';
    }
}
