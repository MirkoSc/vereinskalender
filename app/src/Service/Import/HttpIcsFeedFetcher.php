<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Plain streams client (no curl dependency, works on restrictive shared hosting). Short
 * timeout: the import runs inside PHP request limits (CLAUDE.md section 2).
 */
final class HttpIcsFeedFetcher implements IcsFeedFetcher
{
    /**
     * Cap on the response body. A season feed is a few dozen kilobytes, so
     * this is generous - it exists so a broken or hostile endpoint cannot
     * pull an unbounded body into memory and take the cron run (and with it
     * every other import source) down with an OOM.
     */
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $maxBytes = self::MAX_BYTES,
    ) {
    }

    public function fetch(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSeconds,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'Vereinskalender ICS-Import',
                'ignore_errors' => true,
            ],
        ]);

        // fopen + capped read instead of file_get_contents: the latter has
        // no size limit. $http_response_header is populated by the HTTP
        // wrapper either way, so the status check below is unaffected.
        $handle = @fopen($url, 'rb', context: $context);
        if ($handle === false) {
            throw new \RuntimeException('Feed nicht erreichbar: ' . $url);
        }

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine . ' ', $m) === 1 && (int) $m[1] >= 400) {
            fclose($handle);
            throw new \RuntimeException(sprintf('Feed antwortet mit HTTP %d: %s', (int) $m[1], $url));
        }

        // one byte past the limit, so "exactly at the limit" still passes
        $body = stream_get_contents($handle, $this->maxBytes + 1);
        fclose($handle);

        if ($body === false) {
            throw new \RuntimeException('Feed nicht lesbar: ' . $url);
        }
        if (strlen($body) > $this->maxBytes) {
            throw new \RuntimeException(sprintf(
                'Feed ist größer als %d MB und wurde abgebrochen: %s',
                intdiv($this->maxBytes, 1024 * 1024),
                $url,
            ));
        }

        return $body;
    }
}
