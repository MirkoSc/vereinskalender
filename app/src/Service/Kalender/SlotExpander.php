<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\Occurrence;

/**
 * Expands recurring training slots into concrete occurrences for a date
 * range, honouring validity ranges and slot exceptions.
 *
 * Times are Europe/Berlin wall time: a 19:00 slot is 19:00 local on every
 * occurrence, across both DST transitions (mandatory tests, section 12).
 * Pure logic, no database access.
 */
final class SlotExpander
{
    /**
     * @param list<array<string, mixed>> $slots training_slot rows
     * @param list<array<string, mixed>> $exceptions slot_exception rows
     * @return list<Occurrence> sorted by start, then slot id
     */
    public static function expand(array $slots, array $exceptions, string $von, string $bis): array
    {
        $excluded = [];
        foreach ($exceptions as $exception) {
            $excluded[(int) $exception['slot_id'] . '|' . (string) $exception['datum']] = true;
        }

        $rangeStart = new \DateTimeImmutable($von);
        $rangeEnd = new \DateTimeImmutable($bis);

        $occurrences = [];
        foreach ($slots as $slot) {
            $slotId = (int) $slot['id'];
            $weekday = (int) $slot['wochentag'];

            $first = max($rangeStart, new \DateTimeImmutable((string) $slot['gueltig_ab']));
            $last = min($rangeEnd, new \DateTimeImmutable((string) $slot['gueltig_bis']));

            // advance to the first matching ISO weekday
            $offset = ($weekday - (int) $first->format('N') + 7) % 7;
            $date = $first->modify(sprintf('+%d days', $offset));

            while ($date <= $last) {
                $datum = $date->format('Y-m-d');
                if (!isset($excluded[$slotId . '|' . $datum])) {
                    $occurrences[] = new Occurrence(
                        slotId: $slotId,
                        teamId: (int) $slot['team_id'],
                        pitchId: (int) $slot['pitch_id'],
                        datum: $datum,
                        start: new \DateTimeImmutable($datum . ' ' . (string) $slot['beginn']),
                        end: new \DateTimeImmutable($datum . ' ' . (string) $slot['ende']),
                    );
                }
                $date = $date->modify('+7 days');
            }
        }

        usort(
            $occurrences,
            static fn(Occurrence $a, Occurrence $b): int => [$a->start, $a->slotId] <=> [$b->start, $b->slotId],
        );

        return $occurrences;
    }
}
