<?php

declare(strict_types=1);

namespace App\Service\EventStore;

final readonly class ReplayBatchResult
{
    /**
     * @param list<SkippedEvent> $skipped
     */
    public function __construct(
        public int $lastEventId,
        public int $processed,
        public array $skipped,
        public bool $done,
    ) {
    }
}
