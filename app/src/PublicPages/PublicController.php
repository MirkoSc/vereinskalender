<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Http\Request;
use App\Http\Response;
use App\Repository\PitchRepository;
use App\Repository\SettingRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\View\View;

/**
 * Public calendar pages. Reading never starts a session (CLAUDE.md
 * section 6). Team/venue colors are rendered as CSS variables into the
 * page (:root { --team-<id>: ... }); the pages embed the master data as
 * JSON for the calendar JS.
 */
final readonly class PublicController
{
    public function __construct(
        private View $view,
        private TeamRepository $teams,
        private PitchRepository $pitches,
        private VenueRepository $venues,
        private SettingRepository $settings,
    ) {
    }

    public function home(Request $request): Response
    {
        return Response::html($this->view->render('home', ['title' => 'Vereinskalender']));
    }

    public function belegung(Request $request): Response
    {
        return $this->calendarPage('belegung', 'Platzbelegung');
    }

    public function spielplan(Request $request): Response
    {
        return $this->calendarPage('spielplan', 'Spielplan');
    }

    public function verfuegbarkeit(Request $request): Response
    {
        [$appData, $colorCss] = $this->stammdaten();
        $appData['ansicht'] = 'verfuegbarkeit';

        return Response::html($this->view->render('verfuegbarkeit', [
            'title' => 'Platz-Verfügbarkeit',
            'appData' => $appData,
            'colorCss' => $colorCss,
            'scripts' => ['/js/schreiben.js', '/js/verfuegbarkeit.js'],
        ]));
    }

    private function calendarPage(string $ansicht, string $title): Response
    {
        [$appData, $colorCss] = $this->stammdaten();
        $appData['ansicht'] = $ansicht;

        return Response::html($this->view->render('kalender', [
            'title' => $title,
            'ansicht' => $ansicht,
            'appData' => $appData,
            'colorCss' => $colorCss,
            'scripts' => [
                '/js/vendor/fullcalendar-scheduler.global.min.js',
                '/js/vendor/fullcalendar-locale-de.global.min.js',
                '/js/schreiben.js',
                '/js/kalender.js',
            ],
        ]));
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function stammdaten(): array
    {
        $teams = array_map(static fn(array $t): array => [
            'id' => (int) $t['id'],
            'bereich' => (string) $t['bereich'],
            'name' => (string) $t['name'],
            'kuerzel' => (string) $t['kuerzel'],
            'farbe' => (string) $t['farbe'],
            'aktiv' => (int) $t['aktiv'] === 1,
        ], $this->teams->findAll());

        $venues = array_map(static fn(array $v): array => [
            'id' => (int) $v['id'],
            'name' => (string) $v['name'],
            'farbe' => (string) $v['farbe'],
            'adresse' => (string) $v['adresse'],
        ], $this->venues->findAll());

        $pitches = array_map(static fn(array $p): array => [
            'id' => (int) $p['id'],
            'venue_id' => (int) $p['venue_id'],
            'name' => (string) $p['name'],
            'venue_name' => (string) ($p['venue_name'] ?? ''),
        ], $this->pitches->findAll());

        $auswaertsFarbe = $this->settings->get('auswaerts_farbe', '#57606a');

        $cssLines = ['--auswaerts: ' . $auswaertsFarbe . ';'];
        foreach ($teams as $team) {
            $cssLines[] = sprintf('--team-%d: %s;', $team['id'], $team['farbe']);
        }
        foreach ($venues as $venue) {
            $cssLines[] = sprintf('--venue-%d: %s;', $venue['id'], $venue['farbe']);
        }

        return [
            [
                'teams' => $teams,
                'venues' => $venues,
                'pitches' => $pitches,
                'auswaertsFarbe' => $auswaertsFarbe,
            ],
            ':root { ' . implode(' ', $cssLines) . ' }',
        ];
    }
}
