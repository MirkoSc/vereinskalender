<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Streams a file from disk without loading it into memory.
 */
final readonly class FileResponse implements ResponseInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $filePath,
        public array $headers = [],
        public int $status = 200,
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->status !== 304) {
            readfile($this->filePath);
        }
    }
}
