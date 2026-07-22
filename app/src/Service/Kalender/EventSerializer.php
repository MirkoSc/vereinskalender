<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\Occurrence;
use App\Domain\VermietungArt;

/**
 * Serializes concrete calendar entities (slot occurrence, restriction,
 * match) into the public event shape shared by /api/events and the offline
 * bundle (CLAUDE.md section 7): every event carries both color fields,
 * venue_id, pitch_farbe/pitch_kuerzel. Pure, no database access - all
 * lookups go through the maps passed to the constructor, so it can be
 * reused for a live DB row or an offline-bundle row alike.
 *
 * Issue #65: a match flagged spielfrei is its own category, resolved ahead
 * of the venue_begriff/pitch/auswaerts chain - venue_id stays null and
 * venue_farbe is the dedicated spielfrei color, never the away color.
 */
final readonly class EventSerializer
{
    /**
     * @param array<int, array<string, mixed>> $teamsById
     * @param array<int, array<string, mixed>> $pitchesById
     * @param array<int, array<string, mixed>> $venuesById
     * @param array<int, array<string, mixed>> $sportheimeById
     * @param array<int, array<string, mixed>> $raeumeById
     */
    public function __construct(
        private array $teamsById,
        private array $pitchesById,
        private array $venuesById,
        private VenueMatcher $venueMatcher,
        private string $auswaertsFarbe,
        private string $spielfreiFarbe = '#775c3c',
        private array $sportheimeById = [],
        private array $raeumeById = [],
    ) {
    }

    /**
     * @param array<string, mixed> $slotRow the training_slot row the occurrence stems from
     *        (wochentage/gueltig_ab/gueltig_bis: series data for the edit dialog)
     * @return array<string, mixed>|null null when none of the slot's teams exist (anymore)
     */
    public function belegung(Occurrence $occurrence, array $slotRow): ?array
    {
        $slotTeams = array_values(array_filter(array_map(
            fn(int $teamId): ?array => $this->teamsById[$teamId] ?? null,
            $occurrence->teamIds,
        )));
        if ($slotTeams === []) {
            return null;
        }

        $pitch = $this->pitchesById[$occurrence->pitchId] ?? null;
        $venueId = $pitch !== null ? (int) $pitch['venue_id'] : null;
        $kuerzel = implode('+', array_map(static fn(array $t): string => (string) $t['kuerzel'], $slotTeams));

        return [
            'id' => sprintf('slot-%d-%s', $occurrence->slotId, $occurrence->datum),
            'typ' => 'belegung',
            'slot_id' => $occurrence->slotId,
            'start' => $occurrence->start->format('Y-m-d\TH:i:s'),
            'ende' => $occurrence->end->format('Y-m-d\TH:i:s'),
            'titel' => $kuerzel . ' Training',
            'team_id' => $occurrence->teamIds[0],
            'team_ids' => $occurrence->teamIds,
            'team_name' => implode(' + ', array_map(static fn(array $t): string => (string) $t['name'], $slotTeams)),
            'team_kuerzel' => $kuerzel,
            'team_farbe' => (string) $slotTeams[0]['farbe'],
            'venue_id' => $venueId,
            'venue_farbe' => $venueId !== null
                ? (string) ($this->venuesById[$venueId]['farbe'] ?? $this->auswaertsFarbe)
                : $this->auswaertsFarbe,
            'pitch_id' => $occurrence->pitchId,
            'pitch_name' => $pitch !== null ? (string) $pitch['name'] : null,
            'pitch_kuerzel' => $pitch !== null ? (string) $pitch['kuerzel'] : null,
            'pitch_farbe' => $pitch !== null ? (string) $pitch['farbe'] : null,
            // address fallback for the Maps link (CLAUDE.md section 4:
            // pitch.adresse only set when it differs from the venue's)
            'pitch_adresse' => $pitch !== null && $pitch['adresse'] !== null ? (string) $pitch['adresse'] : null,
            'venue_adresse' => $venueId !== null && isset($this->venuesById[$venueId]) ? (string) $this->venuesById[$venueId]['adresse'] : null,
            // Issue #36: lets the client match this event against a
            // Vermietung of the same Sportheim without a second lookup
            'pitch_sportheim_id' => $pitch !== null && $pitch['sportheim_id'] !== null ? (int) $pitch['sportheim_id'] : null,
            'wochentage' => SlotExpander::intList($slotRow['wochentage']),
            'gueltig_ab' => (string) $slotRow['gueltig_ab'],
            'gueltig_bis' => (string) $slotRow['gueltig_bis'],
        ];
    }

    /**
     * @param array<string, mixed> $restrictionRow
     * @return array<string, mixed>
     */
    public function sperrung(array $restrictionRow): array
    {
        $pitch = $this->pitchesById[(int) $restrictionRow['pitch_id']] ?? null;
        $venueId = $pitch !== null ? (int) $pitch['venue_id'] : null;

        return [
            'id' => 'sperrung-' . (int) $restrictionRow['id'],
            'typ' => 'sperrung',
            'restriction_id' => (int) $restrictionRow['id'],
            'start' => str_replace(' ', 'T', (string) $restrictionRow['von']),
            'ende' => str_replace(' ', 'T', (string) $restrictionRow['bis']),
            'titel' => ((string) $restrictionRow['art'] === 'gesperrt' ? 'Gesperrt: ' : 'Eingeschränkt: ')
                . (string) $restrictionRow['grund'],
            'art' => (string) $restrictionRow['art'],
            'grund' => (string) $restrictionRow['grund'],
            'team_id' => null,
            'team_farbe' => '#000000',
            'venue_id' => $venueId,
            'venue_farbe' => $venueId !== null
                ? (string) ($this->venuesById[$venueId]['farbe'] ?? $this->auswaertsFarbe)
                : $this->auswaertsFarbe,
            'pitch_id' => (int) $restrictionRow['pitch_id'],
            'pitch_name' => $pitch !== null ? (string) $pitch['name'] : null,
            'pitch_kuerzel' => $pitch !== null ? (string) $pitch['kuerzel'] : null,
            'pitch_farbe' => $pitch !== null ? (string) $pitch['farbe'] : null,
            'pitch_adresse' => $pitch !== null && $pitch['adresse'] !== null ? (string) $pitch['adresse'] : null,
            'venue_adresse' => $venueId !== null && isset($this->venuesById[$venueId]) ? (string) $this->venuesById[$venueId]['adresse'] : null,
            'pitch_sportheim_id' => $pitch !== null && $pitch['sportheim_id'] !== null ? (int) $pitch['sportheim_id'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $matchRow
     * @return array<string, mixed>|null null when the match's team doesn't exist (anymore)
     */
    public function spiel(array $matchRow): ?array
    {
        $teamId = (int) $matchRow['team_id'];
        $team = $this->teamsById[$teamId] ?? null;
        if ($team === null) {
            return null;
        }

        $pitchId = $matchRow['pitch_id'] !== null ? (int) $matchRow['pitch_id'] : null;
        $pitch = $pitchId !== null ? ($this->pitchesById[$pitchId] ?? null) : null;
        $spielfrei = (int) ($matchRow['spielfrei'] ?? 0) === 1;

        // display-time venue resolution (retroactive keywords); a match
        // occupying a pitch is by definition at that pitch's venue, so this
        // is the fallback when ort_text matches no begriff (e.g. an empty
        // ort_text on a manual match). Spielfrei (Issue #65) is its own
        // category ahead of this chain - a bye has no venue at all.
        $venueId = $spielfrei ? null : ($this->venueMatcher->match((string) $matchRow['ort_text'])
            ?? ($pitch !== null ? (int) $pitch['venue_id'] : null));

        $anstoss = (string) $matchRow['anstoss'];
        $ende = $matchRow['ende'] !== null ? (string) $matchRow['ende'] : null;
        $start = new \DateTimeImmutable($anstoss);

        // Issue #78: a bye is a whole-day fact, never a timed block. The feed
        // ships spielfrei as a DATE-TIME at a late evening hour (~23:59) on
        // the real day, so the relevant calendar day is the DATE of the
        // START - anstoss stays raw in the DB, only the display derives.
        // start/ende collapse to that day's midnight and allDay carries the
        // whole-day flag; FullCalendar renders it in the all-day slot. (The
        // +2h end fallback must NOT drive the day: a 23:59 kickoff would land
        // the effective end on the following day and push the bye one day too
        // late.)
        if ($spielfrei) {
            $tag = $start->format('Y-m-d') . 'T00:00:00';
        }

        return [
            'id' => 'match-' . (int) $matchRow['id'],
            'typ' => 'spiel',
            'match_id' => (int) $matchRow['id'],
            'manuell' => $matchRow['import_source_id'] === null,
            'allDay' => $spielfrei,
            'start' => $spielfrei ? $tag : $start->format('Y-m-d\TH:i:s'),
            'ende' => $spielfrei ? $tag : MatchDuration::effectiveEnd($anstoss, $ende)->format('Y-m-d\TH:i:s'),
            'titel' => (string) $team['kuerzel'] . ' – ' . (string) $matchRow['gegner'],
            'team_id' => $teamId,
            'team_name' => (string) $team['name'],
            'team_kuerzel' => (string) $team['kuerzel'],
            'team_farbe' => (string) $team['farbe'],
            'venue_id' => $venueId,
            'venue_name' => $venueId !== null ? (string) ($this->venuesById[$venueId]['name'] ?? '') : null,
            'venue_farbe' => match (true) {
                $spielfrei => $this->spielfreiFarbe,
                $venueId !== null => (string) ($this->venuesById[$venueId]['farbe'] ?? $this->auswaertsFarbe),
                default => $this->auswaertsFarbe,
            },
            'pitch_id' => $pitchId,
            'pitch_name' => $pitchId !== null ? (string) ($this->pitchesById[$pitchId]['name'] ?? '') : null,
            'pitch_kuerzel' => $pitch !== null ? (string) $pitch['kuerzel'] : null,
            'pitch_farbe' => $pitch !== null ? (string) $pitch['farbe'] : null,
            'pitch_adresse' => $pitch !== null && $pitch['adresse'] !== null ? (string) $pitch['adresse'] : null,
            'venue_adresse' => $venueId !== null && isset($this->venuesById[$venueId]) ? (string) $this->venuesById[$venueId]['adresse'] : null,
            'pitch_sportheim_id' => $pitch !== null && $pitch['sportheim_id'] !== null ? (int) $pitch['sportheim_id'] : null,
            'gegner' => (string) $matchRow['gegner'],
            'heimspiel' => (int) $matchRow['heimspiel'] === 1,
            'spielfrei' => $spielfrei,
            'ort_text' => (string) $matchRow['ort_text'],
            'status' => (string) $matchRow['status'],
        ];
    }

    /**
     * @param array<string, mixed> $row raw vermietung projection row
     *        (raum_ids as a JSON string, like the DB column)
     * @return array<string, mixed>
     */
    public function vermietung(array $row): array
    {
        $sportheim = $this->sportheimeById[(int) $row['sportheim_id']] ?? null;
        $venueId = $sportheim !== null ? (int) $sportheim['venue_id'] : null;

        $raumIds = array_map(intval(...), (array) json_decode((string) $row['raum_ids'], true));
        $raeume = array_values(array_filter(array_map(
            fn(int $raumId): ?array => $this->raeumeById[$raumId] ?? null,
            $raumIds,
        )));
        $raumText = $raeume === []
            ? 'gesamtes Sportheim'
            : implode('+', array_map(static fn(array $r): string => (string) $r['kuerzel'], $raeume));

        // Issue #63: the title prefix names the art ("Putzen: ..."), so the
        // label is correct offline too - the bundle ships this string
        // pre-serialized.
        $art = VermietungArt::fromPayload($row['art'] ?? null);

        return [
            'id' => 'vermietung-' . (int) $row['id'],
            'typ' => 'vermietung',
            'vermietung_id' => (int) $row['id'],
            'art' => $art->value,
            'start' => str_replace(' ', 'T', (string) $row['von']),
            'ende' => str_replace(' ', 'T', (string) $row['bis']),
            'titel' => sprintf('%s: %s (%s)', $art->label(), (string) $row['titel'], $raumText),
            'anlass' => (string) $row['titel'],
            'sportheim_id' => (int) $row['sportheim_id'],
            'sportheim_name' => $sportheim !== null ? (string) $sportheim['name'] : '',
            'raum_ids' => $raumIds,
            'raum_text' => $raumText,
            'team_id' => null,
            'team_farbe' => null,
            'venue_id' => $venueId,
            'venue_name' => $venueId !== null ? (string) ($this->venuesById[$venueId]['name'] ?? '') : null,
            'venue_farbe' => $venueId !== null
                ? (string) ($this->venuesById[$venueId]['farbe'] ?? $this->auswaertsFarbe)
                : $this->auswaertsFarbe,
            'pitch_id' => null,
            'pitch_name' => null,
            'pitch_kuerzel' => null,
            'pitch_farbe' => null,
            'pitch_adresse' => null,
            'venue_adresse' => $venueId !== null && isset($this->venuesById[$venueId]) ? (string) $this->venuesById[$venueId]['adresse'] : null,
            'kontakt' => $row['kontakt'] !== null ? (string) $row['kontakt'] : null,
            'bemerkung' => $row['bemerkung'] !== null ? (string) $row['bemerkung'] : null,
        ];
    }
}
