<?php

declare(strict_types=1);

namespace App\Service\Import;

interface IcsFeedFetcher
{
    /**
     * @throws \RuntimeException on network/HTTP errors
     */
    public function fetch(string $url): string;
}
