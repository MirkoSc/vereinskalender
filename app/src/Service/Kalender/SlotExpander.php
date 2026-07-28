<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\Occurrence;

/**
 * Expands recurring training slots into concrete occurrences for a date
 * range, honouring validity ranges, multiple weekdays per slot, the
 * recurrence interval in weeks, and slot exceptions.
 *
 * Times are Europe/Berlin wall time: a 19:00 slot is 19:00 local on every
 * occurrence, across both DST transitions (mandatory tests, section 12).
 * Pure logic, no database access.
 */
final class SlotExpander
{
    /**
     * @param list<array<string, mixed>> $slots training_slot rows or payloads;
     *        team_ids/wochentage may be PHP lists or JSON-encoded strings
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
            $teamIds = self::intList($slot['team_ids']);
            $weekdays = self::intList($slot['wochentage']);

            $intervall = max(1, (int) ($slot['intervall_wochen'] ?? 1));
            $gueltigAb = new \DateTimeImmutable((string) $slot['gueltig_ab']);
            $first = max($rangeStart, $gueltigAb);
            $last = min($rangeEnd, new \DateTimeImmutable((string) $slot['gueltig_bis']));
            if ($last < $first) {
                // nothing in range - and it keeps the fast-forward loop below
                // bounded by the validity range instead of by $rangeStart
                continue;
            }

            // Rhythm anchor: the Monday of the week holding the series' FIRST
            // occurrence - the series starts on its earliest possible date and
            // repeats every $intervall weeks from there. Anchoring on the
            // gueltig_ab week instead would silently drop the first week
            // whenever gueltig_ab falls after the chosen weekday within it
            // ("ab Sa 01.08., dienstags, alle 2 Wochen" would start on the
            // 11th, not the 4th). Anchoring the WEEK rather than each weekday
            // keeps all weekdays of a slot in the same weeks; per-weekday
            // anchoring would interleave them (with gueltig_ab on a Tuesday,
            // Mo and Mi of a Mo+Mi slot would land in alternating weeks).
            // Derived from the SLOT only, never from the requested range -
            // otherwise the same slot would render different dates in an
            // August query than in a September one.
            $erste = null;
            foreach ($weekdays as $weekday) {
                $offset = ($weekday - (int) $gueltigAb->format('N') + 7) % 7;
                $kandidat = $gueltigAb->modify(sprintf('+%d days', $offset));
                if ($erste === null || $kandidat < $erste) {
                    $erste = $kandidat;
                }
            }
            if ($erste === null) {
                continue;
            }
            $anker = $erste->modify(sprintf('-%d days', (int) $erste->format('N') - 1));
            $schritt = sprintf('+%d days', 7 * $intervall);

            foreach ($weekdays as $weekday) {
                $date = $anker->modify(sprintf('+%d days', $weekday - 1));
                while ($date < $first) {
                    $date = $date->modify($schritt);
                }

                while ($date <= $last) {
                    $datum = $date->format('Y-m-d');
                    if (!isset($excluded[$slotId . '|' . $datum])) {
                        $occurrences[] = new Occurrence(
                            slotId: $slotId,
                            teamIds: $teamIds,
                            pitchId: (int) $slot['pitch_id'],
                            datum: $datum,
                            start: new \DateTimeImmutable($datum . ' ' . (string) $slot['beginn']),
                            end: new \DateTimeImmutable($datum . ' ' . (string) $slot['ende']),
                        );
                    }
                    $date = $date->modify($schritt);
                }
            }
        }

        usort(
            $occurrences,
            static fn(Occurrence $a, Occurrence $b): int => [$a->start, $a->slotId] <=> [$b->start, $b->slotId],
        );

        return $occurrences;
    }

    /**
     * Accepts a PHP list or a JSON-encoded string (projection rows deliver
     * JSON strings, offline-bundle/validated payloads deliver plain lists).
     *
     * @return list<int>
     */
    public static function intList(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?? [];
        }

        return array_values(array_map(intval(...), (array) $value));
    }
}
