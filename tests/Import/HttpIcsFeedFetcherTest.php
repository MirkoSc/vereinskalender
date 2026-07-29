<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Service\Import\HttpIcsFeedFetcher;
use PHPUnit\Framework\TestCase;

/**
 * The size cap on a feed response. A season feed is a few dozen kilobytes;
 * the limit exists so a broken or hostile endpoint cannot pull an unbounded
 * body into memory and take the whole cron run down with an OOM - which
 * would stop every OTHER import source too, since runAll() shares one
 * process.
 *
 * Driven through file:// URLs: that exercises the real fopen +
 * stream_get_contents path without needing a network or a test server, and
 * the HTTP status check simply finds no $http_response_header, exactly as
 * the null coalescing there expects.
 */
final class HttpIcsFeedFetcherTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/vk_feed_' . uniqid('', true) . '.ics';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function url(string $inhalt): string
    {
        file_put_contents($this->file, $inhalt);

        return 'file://' . $this->file;
    }

    public function testReadsAFeedBelowTheLimit(): void
    {
        $inhalt = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";

        self::assertSame($inhalt, new HttpIcsFeedFetcher(maxBytes: 1024)->fetch($this->url($inhalt)));
    }

    public function testAFeedExactlyAtTheLimitStillPasses(): void
    {
        $inhalt = str_repeat('x', 100);

        self::assertSame($inhalt, new HttpIcsFeedFetcher(maxBytes: 100)->fetch($this->url($inhalt)));
    }

    public function testAnOversizedFeedIsRejectedInsteadOfBufferedWhole(): void
    {
        $url = $this->url(str_repeat('x', 101));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/größer als/');

        new HttpIcsFeedFetcher(maxBytes: 100)->fetch($url);
    }

    /**
     * The failure has to stay a RuntimeException, because that is what
     * IcsImportService::runSource() catches per source - an unreachable
     * feed must not stop the other sources.
     */
    public function testAnUnreachableFeedThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        new HttpIcsFeedFetcher()->fetch('file:///gibt/es/nicht/feed.ics');
    }
}
