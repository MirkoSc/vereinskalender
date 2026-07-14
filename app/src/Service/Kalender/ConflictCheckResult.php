<?php

declare(strict_types=1);

namespace App\Service\Kalender;

final readonly class ConflictCheckResult
{
    /**
     * @param list<string> $conflicts German messages, booking is rejected
     * @param list<string> $warnings German messages ('eingeschraenkt'), booking allowed
     */
    public function __construct(
        public array $conflicts,
        public array $warnings,
    ) {
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }
}
