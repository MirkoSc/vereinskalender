<?php

declare(strict_types=1);

namespace App\Service\Kalender;

final class ConflictException extends \RuntimeException
{
    /**
     * @param list<string> $conflicts German messages
     */
    public function __construct(private readonly array $conflicts)
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
}
