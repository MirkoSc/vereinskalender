<?php

declare(strict_types=1);

namespace App\Service\Kalender;

/**
 * Groups structured conflict occurrences by verursacher (issue #9): every
 * occurrence of the same colliding slot/match/restriction becomes one row
 * with a count and the next occurrence date, instead of one line per
 * expanded date. Pure aggregation/formatting on top of BookingService's
 * result — the conflict detection itself (BookingService::checkPayload) is
 * untouched.
 */
final class ConflictGrouper
{
    /**
     * @param list<Conflict> $details
     * @return list<array{
     *     typ: string,
     *     verursacher_id: int,
     *     label: string,
     *     ist_warnung: bool,
     *     anzahl: int,
     *     naechster_termin: string,
     *     termine: list<array{datum: string, von: string, bis: string, nachricht: string}>,
     * }>
     */
    public static function group(array $details): array
    {
        $groups = [];

        foreach ($details as $detail) {
            $key = $detail->typ . '|' . $detail->verursacherId;
            $groups[$key] ??= [
                'typ' => $detail->typ,
                'verursacher_id' => $detail->verursacherId,
                'label' => $detail->label,
                'ist_warnung' => $detail->istWarnung,
                'termine' => [],
            ];
            $groups[$key]['termine'][] = [
                'datum' => $detail->datum,
                'von' => $detail->von,
                'bis' => $detail->bis,
                'nachricht' => $detail->nachricht,
            ];
        }

        $result = [];
        foreach ($groups as $group) {
            usort(
                $group['termine'],
                static fn(array $a, array $b): int => $a['datum'] <=> $b['datum'],
            );
            $result[] = [
                'typ' => $group['typ'],
                'verursacher_id' => $group['verursacher_id'],
                'label' => $group['label'],
                'ist_warnung' => $group['ist_warnung'],
                'anzahl' => count($group['termine']),
                'naechster_termin' => $group['termine'][0]['datum'],
                'termine' => $group['termine'],
            ];
        }

        usort($result, static fn(array $a, array $b): int => $a['naechster_termin'] <=> $b['naechster_termin']);

        return $result;
    }
}
