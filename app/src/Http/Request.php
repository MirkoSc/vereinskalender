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

        // fetch() sends JSON bodies; expose them like form fields
        $post = $_POST;
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $post = $decoded;
            }
        }

        return new self(
            method: HttpMethod::fromString($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            post: $post,
            headers: self::headersFromServer($_SERVER),
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    /**
     * Whether the current request arrived over TLS. Read from $_SERVER for
     * the same reason fromGlobals() is: it is the scheme of the request PHP
     * is answering right now, not a property of the parsed snapshot.
     *
     * Deliberately NOT derived from X-Forwarded-Proto: that header is
     * client-controlled unless a trusted proxy is configured, and this
     * installation terminates TLS on the web server itself. When the host
     * does not expose HTTPS at all the answer is false, which only ever
     * means "no improvement" - never a broken cookie (see Session::start()).
     */
    public static function httpsFromGlobals(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');

        return $https !== '' && strtolower($https) !== 'off';
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
