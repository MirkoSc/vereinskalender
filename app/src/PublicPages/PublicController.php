<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Http\Request;
use App\Http\Response;
use App\Repository\PageRepository;
use App\Repository\PitchRepository;
use App\Repository\SettingRepository;
use App\Repository\TeamRepository;
use App\Repository\UsageStatRepository;
use App\Repository\VenueRepository;
use App\Support\Markdown;
use App\View\View;

/**
 * Public calendar pages. Reading never starts a session (CLAUDE.md
 * section 6). Team/venue colors are rendered as CSS variables into the
 * page; page views are counted as aggregated usage_stat entries.
 */
final readonly class PublicController
{
    public function __construct(
        private View $view,
        private TeamRepository $teams,
        private PitchRepository $pitches,
        private VenueRepository $venues,
        private SettingRepository $settings,
        private PageRepository $pages,
        private UsageStatRepository $stats,
        private string $version,
        private string $publicDir,
    ) {
    }

    public function home(Request $request): Response
    {
        $this->stats->increment('seite', '/');

        return Response::html($this->view->render('home', ['title' => 'Vereinskalender']));
    }

    public function belegung(Request $request): Response
    {
        $this->stats->increment('seite', '/belegung');

        return $this->calendarPage('belegung', 'Platzbelegung');
    }

    public function spielplan(Request $request): Response
    {
        $this->stats->increment('seite', '/spielplan');

        return $this->calendarPage('spielplan', 'Spielplan');
    }

    public function verfuegbarkeit(Request $request): Response
    {
        $this->stats->increment('seite', '/verfuegbarkeit');
        [$appData, $colorCss] = $this->stammdaten();
        $appData['ansicht'] = 'verfuegbarkeit';

        return Response::html($this->view->render('verfuegbarkeit', [
            'title' => 'Platz-Verfügbarkeit',
            'appData' => $appData,
            'colorCss' => $colorCss,
            'scripts' => ['/js/konflikte.js', '/js/filter.js', '/js/schreiben.js', '/js/offline.js', '/js/push.js', '/js/verfuegbarkeit.js'],
        ]));
    }

    public function abonnieren(Request $request): Response
    {
        $this->stats->increment('seite', '/abonnieren');

        return Response::html($this->view->render('abonnieren', [
            'title' => 'Kalender abonnieren',
            'teams' => array_values(array_filter(
                $this->teams->findAll(),
                static fn(array $t): bool => (int) $t['aktiv'] === 1,
            )),
            'pitches' => $this->pitches->findAll(),
        ]));
    }

    /**
     * @param array<string, string> $params
     */
    public function seite(Request $request, array $params): Response
    {
        $page = $this->pages->find($params['key']);
        if ($page === null) {
            return Response::redirect('/');
        }
        $this->stats->increment('seite', '/' . $page['key']);

        return Response::html($this->view->render('seite', [
            'title' => $page['titel'],
            'seitenTitel' => $page['titel'],
            'inhaltHtml' => Markdown::toHtml($page['inhalt']),
        ]));
    }

    /**
     * The service worker is served through the front controller so the
     * cache name carries the release version (CLAUDE.md section 9).
     */
    public function serviceWorker(Request $request): Response
    {
        $template = file_get_contents($this->publicDir . '/sw.template.js');
        if ($template === false) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'sw fehlt');
        }

        return new Response(200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ], str_replace('__VERSION__', $this->version, $template));
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
                '/js/konflikte.js',
                '/js/filter.js',
                '/js/schreiben.js',
                '/js/offline.js',
                '/js/push.js',
                '/js/nachlade.js',
                '/js/kalender-events.js',
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
            'farbe' => (string) $p['farbe'],
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
