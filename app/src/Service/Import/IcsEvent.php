<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * One parsed VEVENT, times already converted to Europe/Berlin.
 */
final readonly class IcsEvent
{
    public function __construct(
        public string $uid,
        public \DateTimeImmutable $start,
        public string $summary,
        public string $location,
        public int $sequence,
        public bool $cancelled,
    ) {
    }
}
