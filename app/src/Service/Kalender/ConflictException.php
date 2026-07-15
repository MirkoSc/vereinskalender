<?php

declare(strict_types=1);

namespace App\Service\Kalender;

final class ConflictException extends \RuntimeException
{
    /**
     * @param list<string> $conflicts German messages
     * @param list<Conflict> $details structured entries backing $conflicts, for grouping/display
     */
    public function __construct(private readonly array $conflicts, private readonly array $details = [])
    {
        parent::__construct('Booking conflicts: ' . implode('; ', $conflicts));
    }

    /**
     * @return list<string>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return list<Conflict>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
