<?php

declare(strict_types=1);

namespace App\Service\Kalender;

final readonly class ConflictCheckResult
{
    /**
     * @param list<string> $conflicts German messages, booking is rejected
     * @param list<string> $warnings German messages ('eingeschraenkt'), booking allowed
     * @param list<Conflict> $details structured entries backing $conflicts+$warnings, one per overlapping occurrence
     */
    public function __construct(
        public array $conflicts,
        public array $warnings,
        public array $details = [],
    ) {
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
