<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Streams static assets from public/ through PHP.
 *
 * On all-inkl the docroot contains only the shim index.php; Apache cannot
 * serve files above it, so every asset request is rewritten to the front
 * controller and answered here. The realpath guard below is security
 * critical: shared/config.php lives one level above the release root.
 */
final class StaticFileHandler
{
    private const array MIME_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    private const int ONE_YEAR = 31536000;

    /**
     * @param bool $longCache asset URLs carry ?v=<VERSION>, so immutable
     *                        caching is safe for releases; dev builds
     *                        revalidate via Last-Modified instead
     */
    public function __construct(
        private readonly string $publicDir,
        private readonly bool $longCache = true,
    ) {
    }

    public function tryServe(Request $request): ?FileResponse
    {
        if ($request->method !== HttpMethod::Get) {
            return null;
        }

        $path = rawurldecode($request->path);
        if (str_contains($path, "\0")) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$extension] ?? null;
        if ($mime === null) {
            return null;
        }

        $publicDir = realpath($this->publicDir);
        $file = realpath($this->publicDir . $path);
        if ($publicDir === false || $file === false || !is_file($file)) {
            return null;
        }
        if (!str_starts_with($file, $publicDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $lastModified = filemtime($file);
        if ($lastModified === false) {
            return null;
        }

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => $this->longCache
                ? 'public, max-age=' . self::ONE_YEAR . ', immutable'
                : 'no-cache',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
        ];

        $ifModifiedSince = $request->header('if-modified-since');
        $since = $ifModifiedSince !== null ? strtotime($ifModifiedSince) : false;
        if ($since !== false && $since >= $lastModified) {
            return new FileResponse($file, $headers, 304);
        }

        $headers['Content-Length'] = (string) filesize($file);

        return new FileResponse($file, $headers);
    }
}
