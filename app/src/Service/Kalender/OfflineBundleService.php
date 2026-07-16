<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Repository\PitchRepository;
use App\Repository\SettingRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;

/**
 * ONE JSON for the offline window (CLAUDE.md section 9): all events from
 * today to today+7 plus teams, venues, colors, settings, and the
 * availability data - both display modes and all filters must work
 * offline from this bundle alone.
 */
final readonly class OfflineBundleService
{
    public const int WINDOW_DAYS = 7;

    public function __construct(
        private EventFeedService $eventFeed,
        private AvailabilityService $availability,
        private TeamRepository $teams,
        private VenueRepository $venues,
        private PitchRepository $pitches,
        private SettingRepository $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $von = new \DateTimeImmutable('today')->format('Y-m-d');
        $bis = new \DateTimeImmutable('today +' . self::WINDOW_DAYS . ' days')->format('Y-m-d');

        return [
            'stand' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            'von' => $von,
            'bis' => $bis,
            'events' => $this->eventFeed->events(['von' => $von, 'bis' => $bis]),
            'verfuegbarkeit' => $this->availability->compute($von, $bis),
            'teams' => array_map(static fn(array $t): array => [
                'id' => (int) $t['id'],
                'bereich' => (string) $t['bereich'],
                'name' => (string) $t['name'],
                'kuerzel' => (string) $t['kuerzel'],
                'farbe' => (string) $t['farbe'],
                'aktiv' => (int) $t['aktiv'] === 1,
            ], $this->teams->findAll()),
            'venues' => array_map(static fn(array $v): array => [
                'id' => (int) $v['id'],
                'name' => (string) $v['name'],
                'farbe' => (string) $v['farbe'],
                'adresse' => (string) $v['adresse'],
            ], $this->venues->findAll()),
            'pitches' => array_map(static fn(array $p): array => [
                'id' => (int) $p['id'],
                'venue_id' => (int) $p['venue_id'],
                'name' => (string) $p['name'],
                'kuerzel' => (string) $p['kuerzel'],
                'farbe' => (string) $p['farbe'],
                'venue_name' => (string) ($p['venue_name'] ?? ''),
            ], $this->pitches->findAll()),
            'settings' => [
                'auswaerts_farbe' => $this->settings->get('auswaerts_farbe', '#57606a'),
                'nutzungszeiten_von' => $this->settings->get('nutzungszeiten_von', '08:00'),
                'nutzungszeiten_bis' => $this->settings->get('nutzungszeiten_bis', '22:00'),
            ],
        ];
    }
}
