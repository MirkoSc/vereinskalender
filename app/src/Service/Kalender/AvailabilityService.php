<?php

declare(strict_types=1);

namespace App\Service\Kalender;

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
use App\Service\ValidationException;

/**
 * Loads the DB rows for /api/verfuegbarkeit and delegates the actual
 * timeline computation to the pure AvailabilityCalculator (CLAUDE.md
 * section 9), which is also driven by the offline-bundle-shaped golden
 * fixtures the client-side JS port is tested against.
 */
final readonly class AvailabilityService
{
    private const int MAX_RANGE_DAYS = 31;

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
        private SportheimRepository $sportheime,
        private SportheimRaumRepository $raeume,
        private VermietungRepository $vermietungen,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(string $von, string $bis): array
    {
        $rangeStart = new \DateTimeImmutable($von);
        $rangeEnd = new \DateTimeImmutable($bis);
        if ($rangeStart > $rangeEnd) {
            throw new ValidationException(['von' => '„von" muss vor „bis" liegen.']);
        }
        if ($rangeStart->diff($rangeEnd)->days > self::MAX_RANGE_DAYS) {
            throw new ValidationException(['bis' => sprintf('Zeitraum auf %d Tage begrenzt.', self::MAX_RANGE_DAYS)]);
        }

        $teams = $this->teams->findAll();
        $teamsById = [];
        foreach ($teams as $team) {
            $teamsById[(int) $team['id']] = $team;
        }
        $pitches = $this->pitches->findAll();
        $pitchesById = [];
        foreach ($pitches as $pitch) {
            $pitchesById[(int) $pitch['id']] = $pitch;
        }
        $venues = $this->venues->findAll();
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
        $serializer = new EventSerializer($teamsById, $pitchesById, $venuesById, $this->venueMatcher, $auswaertsFarbe, $sportheimeById, $raeumeById);

        $slotRows = $this->slots->findOverlapping($von, $bis);
        $ausnahmen = $this->exceptions->findForSlots(
            array_map(static fn(array $s): int => (int) $s['id'], $slotRows),
        );

        $spiele = [];
        foreach ($this->matches->findInRange($von . ' 00:00:00', $bis . ' 23:59:59') as $match) {
            $event = $serializer->spiel($match);
            if ($event !== null) {
                $spiele[] = $event;
            }
        }

        $sperrungen = array_map(
            $serializer->sperrung(...),
            $this->restrictions->findOverlapping($von . ' 00:00:00', $bis . ' 23:59:59'),
        );

        $vermietungen = array_map(
            $serializer->vermietung(...),
            $this->vermietungen->findInRange($von . ' 00:00:00', $bis . ' 23:59:59'),
        );

        $daten = [
            'slots' => $slotRows,
            'ausnahmen' => $ausnahmen,
            'spiele' => $spiele,
            'sperrungen' => $sperrungen,
            'vermietungen' => $vermietungen,
            'teams' => $teams,
            'venues' => $venues,
            'pitches' => $pitches,
            'settings' => [
                'auswaerts_farbe' => $auswaertsFarbe,
                'nutzungszeiten_von' => $this->settings->get('nutzungszeiten_von', '08:00'),
                'nutzungszeiten_bis' => $this->settings->get('nutzungszeiten_bis', '22:00'),
            ],
        ];

        return AvailabilityCalculator::compute($daten, $von, $bis);
    }
}
