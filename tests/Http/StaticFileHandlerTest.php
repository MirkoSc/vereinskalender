<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\StaticFileHandler;
use PHPUnit\Framework\TestCase;

final class StaticFileHandlerTest extends TestCase
{
    private StaticFileHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new StaticFileHandler(__DIR__ . '/../fixtures/public');
    }

    /**
     * @param array<string, string> $headers
     */
    private static function request(string $path, array $headers = []): Request
    {
        return new Request(HttpMethod::Get, $path, headers: $headers);
    }

    public function testServesCssWithMimeAndCacheHeaders(): void
    {
        $response = $this->handler->tryServe(self::request('/css/app.css'));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame('text/css; charset=utf-8', $response->headers['Content-Type']);
        self::assertArrayHasKey('Cache-Control', $response->headers);
        self::assertArrayHasKey('Last-Modified', $response->headers);
        self::assertArrayHasKey('Content-Length', $response->headers);
    }

    public function testIgnoresUnknownExtensions(): void
    {
        self::assertNull($this->handler->tryServe(self::request('/index.php')));
    }

    public function testRefusesPathTraversal(): void
    {
        // secret.css exists one level ABOVE the public fixture dir
        self::assertNull($this->handler->tryServe(self::request('/css/../../secret.css')));
        self::assertNull($this->handler->tryServe(self::request('/../secret.css')));
        self::assertNull($this->handler->tryServe(self::request('/css/%2E%2E/%2E%2E/secret.css')));
    }

    public function testMissingFileFallsThroughToRouter(): void
    {
        self::assertNull($this->handler->tryServe(self::request('/css/missing.css')));
    }

    public function testReturns304WhenNotModifiedSince(): void
    {
        $mtime = filemtime(__DIR__ . '/../fixtures/public/css/app.css');
        self::assertNotFalse($mtime);

        $response = $this->handler->tryServe(self::request('/css/app.css', [
            'if-modified-since' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ]));

        self::assertNotNull($response);
        self::assertSame(304, $response->status);
    }

    public function testIgnoresNonGetRequests(): void
    {
        $request = new Request(HttpMethod::Post, '/css/app.css');

        self::assertNull($this->handler->tryServe($request));
    }
}
