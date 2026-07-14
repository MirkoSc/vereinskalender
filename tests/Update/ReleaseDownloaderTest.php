<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\ReleaseDownloader;
use PHPUnit\Framework\TestCase;

final class ReleaseDownloaderTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                foreach (glob($file . '/*') ?: [] as $inner) {
                    unlink($inner);
                }
                rmdir($file);
            }
        }
    }

    private function temp(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/vk_test_' . uniqid('', true) . $suffix;
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @param array<string, mixed> $release
     */
    private static function releaseJson(string $tag, bool $prerelease, bool $draft = false): array
    {
        return [
            'tag_name' => $tag,
            'prerelease' => $prerelease,
            'draft' => $draft,
            'assets' => [
                ['name' => 'vereinskalender-' . $tag . '.zip', 'browser_download_url' => 'https://example.test/' . $tag . '.zip'],
                ['name' => 'checksums.txt', 'browser_download_url' => 'https://example.test/' . $tag . '-checksums.txt'],
                ['name' => 'setup.php', 'browser_download_url' => 'https://example.test/setup.php'],
            ],
        ];
    }

    public function testStableChannelUsesReleasesLatest(): void
    {
        $requested = [];
        $downloader = new ReleaseDownloader(function (string $url) use (&$requested): string {
            $requested[] = $url;

            return json_encode(self::releaseJson('v1.2.0', false), JSON_THROW_ON_ERROR);
        });

        $release = $downloader->findLatestRelease('stable');

        self::assertNotNull($release);
        self::assertSame('1.2.0', $release['version']);
        self::assertStringEndsWith('/releases/latest', $requested[0]);
        self::assertSame('https://example.test/v1.2.0.zip', $release['zip_url']);
        self::assertSame('https://example.test/v1.2.0-checksums.txt', $release['checksums_url']);
    }

    public function testBetaChannelTakesNewestIncludingPrereleasesButSkipsDrafts(): void
    {
        $downloader = new ReleaseDownloader(fn(string $url): string => json_encode([
            self::releaseJson('v1.3.0', false, draft: true),
            self::releaseJson('v1.3.0-rc1', true),
            self::releaseJson('v1.2.0', false),
        ], JSON_THROW_ON_ERROR));

        $release = $downloader->findLatestRelease('beta');

        self::assertNotNull($release);
        self::assertSame('1.3.0-rc1', $release['version'], 'newest non-draft, pre-releases included');
    }

    public function testMissingAssetsMeansNoRelease(): void
    {
        $downloader = new ReleaseDownloader(fn(string $url): string => json_encode(
            ['tag_name' => 'v1.0.0', 'assets' => []],
            JSON_THROW_ON_ERROR,
        ));

        self::assertNull($downloader->findLatestRelease('stable'));
    }

    public function testChecksumVerificationAcceptsMatchingFile(): void
    {
        $zip = $this->temp('.zip');
        file_put_contents($zip, 'test-inhalt');
        $checksums = hash('sha256', 'test-inhalt') . '  ' . basename($zip) . "\n";

        new ReleaseDownloader()->verifyChecksum($zip, $checksums);

        $this->addToAssertionCount(1); // no exception
    }

    public function testChecksumVerificationRejectsTamperedFile(): void
    {
        $zip = $this->temp('.zip');
        file_put_contents($zip, 'manipulierter-inhalt');
        $checksums = hash('sha256', 'original-inhalt') . '  ' . basename($zip) . "\n";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Prüfsummen-Fehler');

        new ReleaseDownloader()->verifyChecksum($zip, $checksums);
    }

    public function testChecksumVerificationRejectsMissingEntry(): void
    {
        $zip = $this->temp('.zip');
        file_put_contents($zip, 'inhalt');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Keine Prüfsumme');

        new ReleaseDownloader()->verifyChecksum($zip, "abc  andere-datei.zip\n");
    }

    public function testExtractRoundtrip(): void
    {
        $zipFile = $this->temp('.zip');
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFromString('VERSION', "1.0.0\n");
        $zip->addFromString('app/test.txt', 'hallo');
        $zip->close();

        $target = $this->temp('_dir');
        new ReleaseDownloader()->extractTo($zipFile, $target);

        self::assertSame("1.0.0\n", file_get_contents($target . '/VERSION'));
        self::assertSame('hallo', file_get_contents($target . '/app/test.txt'));

        // manual cleanup of the nested dir for tearDown
        unlink($target . '/app/test.txt');
        rmdir($target . '/app');
    }

    /**
     * Regression for the setup.php installation failure: the local file
     * must keep the asset name, otherwise verifyChecksum never finds an
     * entry in checksums.txt.
     */
    public function testZipFilenameKeepsAssetName(): void
    {
        self::assertSame(
            'vereinskalender-v0.3.0.zip',
            ReleaseDownloader::zipFilename('https://github.com/MirkoSc/vereinskalender/releases/download/v0.3.0/vereinskalender-v0.3.0.zip'),
        );
        self::assertSame('release.zip', ReleaseDownloader::zipFilename('https://example.test/'));
    }

    public function testDownloadToCopiesLocalStream(): void
    {
        $source = $this->temp('.src');
        file_put_contents($source, 'release-daten');
        $target = $this->temp('.zip');

        new ReleaseDownloader()->downloadTo($source, $target);

        self::assertSame('release-daten', file_get_contents($target));
    }
}
