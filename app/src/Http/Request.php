<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Immutable HTTP request snapshot.
 *
 * The path is NOT url-decoded; route params are decoded individually
 * when they are extracted (Router) and static file paths are decoded
 * behind the realpath guard (StaticFileHandler).
 */
final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, string> $headers lowercase header names
     */
    public function __construct(
        public HttpMethod $method,
        public string $path,
        public array $query = [],
        public array $post = [],
        public array $headers = [],
        public string $ip = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        return new self(
            method: HttpMethod::fromString($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            post: $_POST,
            headers: self::headersFromServer($_SERVER),
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
