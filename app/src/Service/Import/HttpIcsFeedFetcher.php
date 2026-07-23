<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Plain streams client (no curl dependency, works on restrictive shared hosting). Short
 * timeout: the import runs inside PHP request limits (CLAUDE.md section 2).
 */
final class HttpIcsFeedFetcher implements IcsFeedFetcher
{
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
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

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('Feed nicht erreichbar: ' . $url);
        }

        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/\s(\d{3})\s/', $statusLine . ' ', $m) === 1 && (int) $m[1] >= 400) {
            throw new \RuntimeException(sprintf('Feed antwortet mit HTTP %d: %s', (int) $m[1], $url));
        }

        return $body;
    }
}
