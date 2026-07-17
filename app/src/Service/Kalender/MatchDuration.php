<?php

declare(strict_types=1);

namespace App\Service\Kalender;

/**
 * Effective end of a match's occupancy (CLAUDE.md section 3): manually
 * created matches may carry an explicit `ende`; every other match (imported,
 * or manual without one) falls back to kickoff + 2 hours. Centralizes the
 * fallback so availability, conflict checking, the calendar feed and the ICS
 * export agree on one duration.
 */
final class MatchDuration
{
    public const string DEFAULT_DURATION = '+2 hours';

    public static function effectiveEnd(string $anstoss, ?string $ende): \DateTimeImmutable
    {
        if ($ende !== null && $ende !== '') {
            return new \DateTimeImmutable($ende);
        }

        return new \DateTimeImmutable($anstoss)->modify(self::DEFAULT_DURATION);
    }
}
