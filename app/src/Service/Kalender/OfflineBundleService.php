<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Repository\BereichRepository;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Repository\SettingRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\SportheimRaumRepository;
use App\Repository\SportheimRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\VenueRepository;
use App\Repository\VermietungRepository;

/**
 * ONE JSON with the complete dataset (CLAUDE.md section 8, Issue #25): all
 * matches (past+future) and restrictions already serialized in the public
 * event shape, training slots as RAW RULES (the client expands them within
 * gueltig_ab/bis, same as SlotExpander) plus their exceptions, and teams,
 * venues, pitches, colors, settings. All four public views - including
 * Verfuegbarkeit - must work offline from this bundle alone; the client
 * computes availability itself (offline-verfuegbarkeit.js, ported from
 * AvailabilityCalculator, parity-tested against it).
 *
 * format is bumped whenever the shape changes; VKOffline.load() discards
 * bundles with a mismatched format (treated as "no data", refreshed on the
 * next online visit).
 */
final readonly class OfflineBundleService
{
    // Issue #36: bumped to 4 - new sportheime/sportheim_raeume/vermietungen
    // lists (the Sportheim-Vermietung termin type) and pitches now carry
    // sportheim_id; older cached bundles are treated as "no data"
    // (VKOffline.load()) and get replaced on the next online visit.
    // Issue #65: bumped to 5 - spiele now carry a spielfrei flag and
    // settings carries spielfrei_farbe; an older cached bundle would still
    // render a bye as an away match.
    // Issue #63: bumped to 6 - vermietungen now carry an art
    // (vermietung/putzen/sitzung) and their titel is prefixed with it; an
    // older cached bundle would label a cleaning slot as a rental.
    // Issue #78: bumped to 7 - bye (spielfrei) spiele are now whole-day: they
    // carry allDay=true and a day-midnight start/ende (date of the effective
    // end) instead of a ~23:59 kickoff; an older cached bundle would still
    // render a bye as a timed block on the wrong day.
    // Slot-Rhythmus: bumped to 8 - slots now carry intervall_wochen (1 =
    // weekly); an older cached bundle would expand an every-other-week
    // series as a weekly one and show twice the training sessions.
    public const int FORMAT = 8;

    public function __construct(
        private TrainingSlotRepository $slots,
        private SlotExceptionRepository $exceptions,
        private PitchRestrictionRepository $restrictions,
        private MatchRepository $matches,
        private TeamRepository $teams,
        private PitchRepository $pitches,
        private VenueRepository $venues,
        private SettingRepository $settings,
        private VenueMatcher $venueMatcher,
        private BereichRepository $bereiche,
        private SportheimRepository $sportheime,
        private SportheimRaumRepository $raeume,
        private VermietungRepository $vermietungen,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $teams = $this->teams->findAll();
        $pitches = $this->pitches->findAll();
        $venues = $this->venues->findAll();

        $teamsById = [];
        foreach ($teams as $team) {
            $teamsById[(int) $team['id']] = $team;
        }
        $pitchesById = [];
        foreach ($pitches as $pitch) {
            $pitchesById[(int) $pitch['id']] = $pitch;
        }
        $venuesById = [];
        foreach ($venues as $venue) {
            $venuesById[(int) $venue['id']] = $venue;
        }
        $sportheimeById = [];
        foreach ($this->sportheime->findAll() as $sportheim) {
            $sportheimeById[(int) $sportheim['id']] = $sportheim;
        }
        $raeumeById = [];
        foreach ($this->raeume->findAll() as $raum) {
            $raeumeById[(int) $raum['id']] = $raum;
        }
        $auswaertsFarbe = $this->settings->get('auswaerts_farbe', '#57606a');
        $spielfreiFarbe = $this->settings->get('spielfrei_farbe', '#775c3c');
        $serializer = new EventSerializer($teamsById, $pitchesById, $venuesById, $this->venueMatcher, $auswaertsFarbe, $spielfreiFarbe, $sportheimeById, $raeumeById);

        $spiele = [];
        foreach ($this->matches->findAll() as $match) {
            $event = $serializer->spiel($match);
            if ($event !== null) {
                $spiele[] = $event;
            }
        }

        return [
            'format' => self::FORMAT,
            'stand' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            'spiele' => $spiele,
            'sperrungen' => array_map($serializer->sperrung(...), $this->restrictions->findAll()),
            'slots' => array_map(static fn(array $s): array => [
                'id' => (int) $s['id'],
                'team_ids' => SlotExpander::intList($s['team_ids']),
                'pitch_id' => (int) $s['pitch_id'],
                'wochentage' => SlotExpander::intList($s['wochentage']),
                'intervall_wochen' => max(1, (int) ($s['intervall_wochen'] ?? 1)),
                'beginn' => (string) $s['beginn'],
                'ende' => (string) $s['ende'],
                'gueltig_ab' => (string) $s['gueltig_ab'],
                'gueltig_bis' => (string) $s['gueltig_bis'],
            ], $this->slots->findAll()),
            'ausnahmen' => array_map(static fn(array $e): array => [
                'slot_id' => (int) $e['slot_id'],
                'datum' => (string) $e['datum'],
            ], $this->exceptions->findAll()),
            'teams' => array_map(static fn(array $t): array => [
                'id' => (int) $t['id'],
                'bereich_id' => $t['bereich_id'] !== null ? (int) $t['bereich_id'] : null,
                'name' => (string) $t['name'],
                'kuerzel' => (string) $t['kuerzel'],
                'farbe' => (string) $t['farbe'],
                'aktiv' => (int) $t['aktiv'] === 1,
            ], $teams),
            'bereiche' => array_map(static fn(array $b): array => [
                'id' => (int) $b['id'],
                'name' => (string) $b['name'],
                'kuerzel' => (string) $b['kuerzel'],
                'sortierung' => (int) $b['sortierung'],
            ], $this->bereiche->findAktive()),
            'venues' => array_map(static fn(array $v): array => [
                'id' => (int) $v['id'],
                'name' => (string) $v['name'],
                'farbe' => (string) $v['farbe'],
                'adresse' => (string) $v['adresse'],
            ], $venues),
            'pitches' => array_map(static fn(array $p): array => [
                'id' => (int) $p['id'],
                'venue_id' => (int) $p['venue_id'],
                'sportheim_id' => $p['sportheim_id'] !== null ? (int) $p['sportheim_id'] : null,
                'name' => (string) $p['name'],
                'kuerzel' => (string) $p['kuerzel'],
                'farbe' => (string) $p['farbe'],
                'adresse' => $p['adresse'] !== null ? (string) $p['adresse'] : null,
                'venue_name' => (string) ($p['venue_name'] ?? ''),
            ], $pitches),
            'sportheime' => array_map(static fn(array $s): array => [
                'id' => (int) $s['id'],
                'venue_id' => (int) $s['venue_id'],
                'name' => (string) $s['name'],
                'adresse' => $s['adresse'] !== null ? (string) $s['adresse'] : null,
                'sortierung' => (int) $s['sortierung'],
                'aktiv' => (int) $s['aktiv'] === 1,
            ], $this->sportheime->findAktive()),
            'sportheim_raeume' => array_map(static fn(array $r): array => [
                'id' => (int) $r['id'],
                'sportheim_id' => (int) $r['sportheim_id'],
                'name' => (string) $r['name'],
                'kuerzel' => (string) $r['kuerzel'],
                'sortierung' => (int) $r['sortierung'],
                'aktiv' => (int) $r['aktiv'] === 1,
            ], $this->raeume->findAll()),
            'vermietungen' => array_map($serializer->vermietung(...), $this->vermietungen->findAll()),
            'settings' => [
                'auswaerts_farbe' => $auswaertsFarbe,
                'spielfrei_farbe' => $spielfreiFarbe,
                'nutzungszeiten_von' => $this->settings->get('nutzungszeiten_von', '08:00'),
                'nutzungszeiten_bis' => $this->settings->get('nutzungszeiten_bis', '22:00'),
            ],
        ];
    }
}
