<?php

declare(strict_types=1);

namespace App\Service\Kalender;

/**
 * Earliest date on which a training slot can still produce an occurrence
 * after a given date (Issue #52).
 *
 * This is deliberately a LOWER BOUND, not the exact next occurrence: slot
 * exceptions are NOT consulted. An exception can only ever REMOVE an
 * occurrence, so ignoring them can make the answer too early, never too
 * late - and "too early" costs the Terminliste at most one extra empty
 * batch, while "too late" would make it stop before an existing event.
 * That asymmetry is the whole point of the bound; see EventFeedService::
 * naechsterTermin() and CLAUDE.md section 7.
 *
 * Pure logic, no database access - mirrored in public/js/offline-events.js
 * so online and offline share the behaviour.
 */
final class NextEventDate
{
    /**
     * @param list<array<string, mixed>> $slots training_slot rows; wochentage
     *        may be a PHP list or a JSON-encoded string (as SlotExpander)
     * @param string $nach date 'Y-m-d'; only occurrences strictly after it count
     */
    public static function ausSlots(array $slots, string $nach): ?string
    {
        $frueheste = null;
        $abDatum = (new \DateTimeImmutable($nach))->modify('+1 day');

        foreach ($slots as $slot) {
            $gueltigBis = new \DateTimeImmutable((string) $slot['gueltig_bis']);
            if ($gueltigBis < $abDatum) {
                continue;
            }
            $start = max($abDatum, new \DateTimeImmutable((string) $slot['gueltig_ab']));

            foreach (SlotExpander::intList($slot['wochentage']) as $weekday) {
                // first matching ISO weekday on or after $start (as SlotExpander)
                $offset = ($weekday - (int) $start->format('N') + 7) % 7;
                $datum = $start->modify(sprintf('+%d days', $offset));
                if ($datum > $gueltigBis) {
                    continue;
                }
                $kandidat = $datum->format('Y-m-d');
                if ($frueheste === null || $kandidat < $frueheste) {
                    $frueheste = $kandidat;
                }
            }
        }

        return $frueheste;
    }

    /**
     * Smallest non-null date, or null when every candidate is null.
     *
     * @param list<?string> $kandidaten dates 'Y-m-d'
     */
    public static function frueheste(array $kandidaten): ?string
    {
        $frueheste = null;
        foreach ($kandidaten as $kandidat) {
            if ($kandidat !== null && ($frueheste === null || $kandidat < $frueheste)) {
                $frueheste = $kandidat;
            }
        }

        return $frueheste;
    }
}
