<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Repository\BereichRepository;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Repository\SettingRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Repository\VenueRepository;

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
    // Issue #27: bumped to 3 - teams now carry bereich_id, and the bundle
    // gained a `bereiche` list (the dynamic bereich aggregate instead of the
    // former fixed enum); older cached bundles are treated as "no data"
    // (VKOffline.load()) and get replaced on the next online visit.
    public const int FORMAT = 3;

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
        $auswaertsFarbe = $this->settings->get('auswaerts_farbe', '#57606a');
        $serializer = new EventSerializer($teamsById, $pitchesById, $venuesById, $this->venueMatcher, $auswaertsFarbe);

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
                'name' => (string) $p['name'],
                'kuerzel' => (string) $p['kuerzel'],
                'farbe' => (string) $p['farbe'],
                'adresse' => $p['adresse'] !== null ? (string) $p['adresse'] : null,
                'venue_name' => (string) ($p['venue_name'] ?? ''),
            ], $pitches),
            'settings' => [
                'auswaerts_farbe' => $auswaertsFarbe,
                'nutzungszeiten_von' => $this->settings->get('nutzungszeiten_von', '08:00'),
                'nutzungszeiten_bis' => $this->settings->get('nutzungszeiten_bis', '22:00'),
            ],
        ];
    }
}
