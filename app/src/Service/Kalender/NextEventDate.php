<?php

declare(strict_types=1);

namespace App\Service\Kalender;

/**
 * Earliest date on which a training slot can still produce an occurrence
 * after a given date (Issue #52), plus its mirror image ausSlotsVor()/
 * spaeteste() for the Vergangenheits-Nachladen (Issue #81, "Vergangenheit
 * anzeigen" toggle in der Terminliste).
 *
 * This is deliberately a LOWER BOUND (respectively an upper bound facing
 * backwards), not the exact next/previous occurrence: slot exceptions are
 * NOT consulted. An exception can only ever REMOVE an occurrence, so
 * ignoring them can make the answer too early/too recent, never too
 * late/too far back - and that costs the Terminliste at most one extra
 * empty batch, while the other direction would drop an existing event off
 * the end (or the top) of the list. That asymmetry is the whole point of
 * the bound; see EventFeedService::naechsterTermin()/vorherigerTermin() and
 * CLAUDE.md section 7/8.
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

    /**
     * Latest date on which a training slot could still have produced an
     * occurrence before a given date (Issue #81: mirror of ausSlots() for
     * the Terminliste's "Vergangenheit anzeigen" toggle - `von` grows
     * backwards instead of `bis` forwards).
     *
     * Same lower-bound-style guarantee as ausSlots(), just facing the other
     * direction: a deliberate UPPER bound on how far back the previous
     * occurrence lies (slot exceptions are ignored - they can only REMOVE an
     * occurrence, never add one, so ignoring them can make the answer too
     * late/recent, never too early - "too late" costs one extra empty batch,
     * "too early" would drop existing history off the top of the list).
     *
     * @param list<array<string, mixed>> $slots training_slot rows; wochentage
     *        may be a PHP list or a JSON-encoded string (as SlotExpander)
     * @param string $vor date 'Y-m-d'; only occurrences strictly before it count
     */
    public static function ausSlotsVor(array $slots, string $vor): ?string
    {
        $spaeteste = null;
        $bisDatum = (new \DateTimeImmutable($vor))->modify('-1 day');

        foreach ($slots as $slot) {
            $gueltigAb = new \DateTimeImmutable((string) $slot['gueltig_ab']);
            if ($gueltigAb > $bisDatum) {
                continue;
            }
            $ende = min($bisDatum, new \DateTimeImmutable((string) $slot['gueltig_bis']));

            foreach (SlotExpander::intList($slot['wochentage']) as $weekday) {
                // latest matching ISO weekday on or before $ende
                $offset = ((int) $ende->format('N') - $weekday + 7) % 7;
                $datum = $ende->modify(sprintf('-%d days', $offset));
                if ($datum < $gueltigAb) {
                    continue;
                }
                $kandidat = $datum->format('Y-m-d');
                if ($spaeteste === null || $kandidat > $spaeteste) {
                    $spaeteste = $kandidat;
                }
            }
        }

        return $spaeteste;
    }

    /**
     * Largest non-null date, or null when every candidate is null - mirror
     * of frueheste() for the Vergangenheits-Nachladen (Issue #81).
     *
     * @param list<?string> $kandidaten dates 'Y-m-d'
     */
    public static function spaeteste(array $kandidaten): ?string
    {
        $spaeteste = null;
        foreach ($kandidaten as $kandidat) {
            if ($kandidat !== null && ($spaeteste === null || $kandidat > $spaeteste)) {
                $spaeteste = $kandidat;
            }
        }

        return $spaeteste;
    }
}
