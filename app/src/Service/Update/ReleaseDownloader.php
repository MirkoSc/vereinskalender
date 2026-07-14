<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * Shared release download/verify/extract logic (CLAUDE.md section 10):
 * used by the self-updater AND inlined into setup.php by
 * bin/build_setup.php - keep this class dependency-free (no App\ imports).
 *
 * The download URL is hardwired to the project repository and never comes
 * from user input.
 */
final class ReleaseDownloader
{
    public const string REPO = 'MirkoSc/vereinskalender';

    /** @var \Closure(string): string */
    private readonly \Closure $httpGet;

    /**
     * @param (\Closure(string): string)|null $httpGet override for tests
     */
    public function __construct(?\Closure $httpGet = null)
    {
        $this->httpGet = $httpGet ?? self::defaultHttpGet(...);
    }

    /**
     * Channel 'stable' uses /releases/latest (GitHub excludes pre-releases
     * there); 'beta' takes the newest release including pre-releases.
     *
     * @return array{version: string, zip_url: string, checksums_url: string}|null
     */
    public function findLatestRelease(string $channel = 'stable'): ?array
    {
        $base = 'https://api.github.com/repos/' . self::REPO;

        try {
            if ($channel === 'beta') {
                $releases = json_decode(($this->httpGet)($base . '/releases?per_page=10'), true);
                foreach (is_array($releases) ? $releases : [] as $release) {
                    if (is_array($release) && ($release['draft'] ?? false) !== true) {
                        $info = self::releaseInfo($release);
                        if ($info !== null) {
                            return $info;
                        }
                    }
                }

                return null;
            }

            $release = json_decode(($this->httpGet)($base . '/releases/latest'), true);

            return is_array($release) ? self::releaseInfo($release) : null;
        } catch (\RuntimeException $e) {
            // no (regular) release yet: GitHub answers 404 on /releases/latest
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
    }

    public function fetchText(string $url): string
    {
        return ($this->httpGet)($url);
    }

    /**
     * Local filename for a downloaded release ZIP. MUST be the original
     * asset name: verifyChecksum() matches checksums.txt entries by
     * basename, so an arbitrary local name would never match.
     */
    public static function zipFilename(string $zipUrl): string
    {
        $name = basename((string) parse_url($zipUrl, PHP_URL_PATH));

        return $name !== '' ? $name : 'release.zip';
    }

    /**
     * Streams the (possibly large) ZIP to disk without loading it into
     * memory; GitHub asset URLs redirect, follow_location handles that.
     */
    public function downloadTo(string $url, string $targetFile): void
    {
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $source = @fopen($url, 'rb', context: self::httpContext());
        if ($source === false) {
            throw new \RuntimeException('Download fehlgeschlagen: ' . $url);
        }
        $target = fopen($targetFile, 'wb');
        if ($target === false) {
            fclose($source);
            throw new \RuntimeException('Zieldatei nicht beschreibbar: ' . $targetFile);
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if (filesize($targetFile) === 0) {
            throw new \RuntimeException('Download ist leer: ' . $url);
        }
    }

    /**
     * checksums.txt format: "<sha256>  <filename>" per line (sha256sum).
     */
    public function verifyChecksum(string $zipFile, string $checksumsContent): void
    {
        $expected = null;
        $basename = basename($zipFile);
        foreach (preg_split('/\r\n|\n|\r/', $checksumsContent) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m) === 1
                && trim($m[2]) === $basename) {
                $expected = strtolower($m[1]);
                break;
            }
        }
        if ($expected === null) {
            throw new \RuntimeException('Keine Prüfsumme für ' . $basename . ' in checksums.txt gefunden.');
        }

        $actual = hash_file('sha256', $zipFile);
        if ($actual === false || !hash_equals($expected, $actual)) {
            throw new \RuntimeException('Prüfsummen-Fehler: das heruntergeladene ZIP ist beschädigt oder manipuliert.');
        }
    }

    public function extractTo(string $zipFile, string $targetDir): void
    {
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('ZIP kann nicht geöffnet werden: ' . $zipFile);
        }
        if (!$zip->extractTo($targetDir)) {
            $zip->close();
            throw new \RuntimeException('ZIP kann nicht entpackt werden nach: ' . $targetDir);
        }
        $zip->close();
    }

    /**
     * @param array<string, mixed> $release
     * @return array{version: string, zip_url: string, checksums_url: string}|null
     */
    private static function releaseInfo(array $release): ?array
    {
        $version = ltrim((string) ($release['tag_name'] ?? ''), 'v');
        $zipUrl = null;
        $checksumsUrl = null;

        foreach (is_array($release['assets'] ?? null) ? $release['assets'] : [] as $asset) {
            $name = (string) ($asset['name'] ?? '');
            $url = (string) ($asset['browser_download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (preg_match('/^vereinskalender-.+\.zip$/', $name) === 1) {
                $zipUrl = $url;
            } elseif ($name === 'checksums.txt') {
                $checksumsUrl = $url;
            }
        }

        if ($version === '' || $zipUrl === null || $checksumsUrl === null) {
            return null;
        }

        return ['version' => $version, 'zip_url' => $zipUrl, 'checksums_url' => $checksumsUrl];
    }

    private static function defaultHttpGet(string $url): string
    {
        $body = @file_get_contents($url, false, self::httpContext());
        if ($body === false) {
            throw new \RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . $url);
        }

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine . ' ', $m) === 1 && (int) $m[1] >= 400) {
            throw new \RuntimeException(sprintf('HTTP %d für %s', (int) $m[1], $url));
        }

        return $body;
    }

    /**
     * @return resource
     */
    private static function httpContext()
    {
        return stream_context_create([
            'http' => [
                'timeout' => 20,
                'follow_location' => 1,
                'max_redirects' => 5,
                'user_agent' => 'Vereinskalender-Updater',
                'ignore_errors' => true,
            ],
        ]);
    }
}
