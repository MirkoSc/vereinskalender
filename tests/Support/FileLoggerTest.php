<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\FileLogger;
use PHPUnit\Framework\TestCase;

final class FileLoggerTest extends TestCase
{
    private string $dir;
    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vk_log_' . uniqid('', true);
        $this->file = $this->dir . '/var/log/app.log';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '.1'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        foreach ([$this->dir . '/var/log', $this->dir . '/var', $this->dir] as $d) {
            if (is_dir($d)) {
                rmdir($d);
            }
        }
    }

    public function testAppendCreatesTheDirectoryAndTimestampsEachLine(): void
    {
        $logger = new FileLogger($this->file);

        $logger->append('PDOException: kaputt [GET /api/events] at Foo.php:12');

        $inhalt = (string) file_get_contents($this->file);
        self::assertStringContainsString('PDOException: kaputt', $inhalt);
        self::assertMatchesRegularExpression('/^\[\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\] /', $inhalt);
        self::assertStringEndsWith("\n", $inhalt);
    }

    public function testAppendKeepsPreviousLines(): void
    {
        $logger = new FileLogger($this->file);

        $logger->append('erste');
        $logger->append('zweite');

        self::assertSame(2, substr_count((string) file_get_contents($this->file), "\n"));
    }

    /**
     * Unbounded growth is the worse failure on a hosting package with a
     * fixed quota: a full disk takes the whole site down, which is exactly
     * what this log is meant to help debug.
     */
    public function testRotatesOnceTheFileGrewPastTheLimit(): void
    {
        $logger = new FileLogger($this->file, maxBytes: 200);

        for ($i = 0; $i < 12; $i++) {
            $logger->append(str_repeat('x', 40));
        }

        self::assertFileExists($this->file . '.1', 'the previous generation is kept');
        self::assertLessThan(
            400,
            (int) filesize($this->file),
            'the live file starts over instead of growing without end',
        );
    }

    /**
     * It runs inside the global error handler, so it must never turn a
     * handled error into an unhandled one.
     */
    public function testAnUnwritableTargetIsSwallowed(): void
    {
        // a path whose parent is a FILE cannot be created as a directory
        $blocker = sys_get_temp_dir() . '/vk_log_blocker_' . uniqid('', true);
        file_put_contents($blocker, 'x');

        try {
            new FileLogger($blocker . '/var/log/app.log')->append('egal');
            self::assertTrue(true, 'no exception escaped');
        } finally {
            unlink($blocker);
        }
    }
}
