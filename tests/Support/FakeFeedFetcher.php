<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Import\IcsFeedFetcher;

final class FakeFeedFetcher implements IcsFeedFetcher
{
    /**
     * @param array<string, string|\RuntimeException> $responses url => body or exception
     */
    public function __construct(private array $responses)
    {
    }

    public function set(string $url, string|\RuntimeException $response): void
    {
        $this->responses[$url] = $response;
    }

    public function fetch(string $url): string
    {
        $response = $this->responses[$url] ?? new \RuntimeException('No fake response for ' . $url);
        if ($response instanceof \RuntimeException) {
            throw $response;
        }

        return $response;
    }
}
