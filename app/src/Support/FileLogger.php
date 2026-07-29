<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Appends error lines to shared/var/log/ (CLAUDE.md section 2).
 *
 * The directory has existed since the first installer but nothing ever
 * wrote to it: the only log call was error_log(), which on this shared
 * hosting lands in the provider's PHP log - not reliably readable from the
 * customer panel and rotated away on its own schedule. For a system whose
 * operations monitoring is part of the concept that is a blind spot: a 500
 * in the public write path left no trace the admin could find.
 *
 * Deliberately tiny and dependency-free. Every write is best effort - a
 * logger that throws would turn a handled error into an unhandled one, and
 * it runs inside the global error handler of all places.
 */
final readonly class FileLogger
{
    public const int DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private string $file,
        private int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
    }

    public function append(string $line): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $this->rotate();

        @file_put_contents(
            $this->file,
            sprintf("[%s] %s\n", new \DateTimeImmutable()->format('c'), rtrim($line, "\r\n")),
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * One generation is enough here: the file is read when something broke,
     * not mined for history, and unbounded growth on a hosting package with
     * a fixed quota is the worse failure - a full disk would take the whole
     * site down, which is precisely what the log is supposed to help debug.
     */
    private function rotate(): void
    {
        $size = @filesize($this->file);
        if ($size === false || $size < $this->maxBytes) {
            return;
        }

        @rename($this->file, $this->file . '.1');
    }
}
