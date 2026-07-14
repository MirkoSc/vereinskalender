<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * One concrete occurrence of a recurring training slot, expanded for a
 * requested date range. Times are local Europe/Berlin wall time.
 */
final readonly class Occurrence
{
    /**
     * @param list<int> $teamIds 1..n teams (joint training shares one slot)
     */
    public function __construct(
        public int $slotId,
        public array $teamIds,
        public int $pitchId,
        public string $datum,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }
}
