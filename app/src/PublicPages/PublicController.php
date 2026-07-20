<?php

declare(strict_types=1);

namespace App\PublicPages;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Repository\BereichRepository;
use App\Repository\PageRepository;
use App\Repository\PitchRepository;
use App\Repository\SettingRepository;
use App\Repository\SportheimRaumRepository;
use App\Repository\SportheimRepository;
use App\Repository\TeamRepository;
use App\Repository\UsageStatRepository;
use App\Repository\VenueRepository;
use App\Service\Wappen\WappenService;
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
        private WappenService $wappen,
        private BereichRepository $bereiche,
        private SportheimRepository $sportheime,
        private SportheimRaumRepository $raeume,
    ) {
    }

    public function home(Request $request): Response
    {
        $this->stats->increment('seite', '/');
        [$appData, $colorCss] = $this->stammdaten();

        return Response::html($this->view->render('home', [
            'title' => 'Vereinskalender',
            'appData' => $appData,
            'colorCss' => $colorCss,
            'scripts' => ['/js/legende-gruppierung.js', '/js/legende.js'],
        ]));
    }

    /**
     * Eigene, teilbare Seite (Issue #38) mit derselben Legende-Komponente
     * wie die Startseite (einklappbar) und der Overlay im Kalender - alle
     * drei füllen [data-legende] aus derselben appData (public/js/legende.js).
     */
    public function legende(Request $request): Response
    {
        $this->stats->increment('seite', '/legende');
        [$appData, $colorCss] = $this->stammdaten();

        return Response::html($this->view->render('legende', [
            'title' => 'Legende',
            'appData' => $appData,
            'colorCss' => $colorCss,
            'scripts' => ['/js/legende-gruppierung.js', '/js/legende.js'],
        ]));
    }

    /**
     * Issue #37: Spielplan + Platzbelegung sind eine Seite geworden - alte
     * geteilte Links leiten (mit Query-String, damit Filter erhalten
     * bleiben) auf /kalender um, statt selbst zu zählen (die Landung auf
     * /kalender zählt bereits, ein zweiter Increment wäre doppelt).
     */
    public function belegung(Request $request): Response
    {
        return Response::redirect($this->kalenderZiel($request), 301);
    }

    public function spielplan(Request $request): Response
    {
        return Response::redirect($this->kalenderZiel($request), 301);
    }

    /**
     * Eine Kalenderseite mit vier Darstellungen (Tag/Woche/Monat/Liste,
     * Issue #37) statt der früheren getrennten Platzbelegung/Spielplan-
     * Seiten - beide teilten ohnehin schon Template und Skript.
     */
    public function kalender(Request $request): Response
    {
        $this->stats->increment('seite', '/kalender');
        [$appData, $colorCss] = $this->stammdaten();

        return Response::html($this->view->render('kalender', [
            'title' => 'Kalender',
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
                '/js/offline-events.js',
                '/js/legende-gruppierung.js',
                '/js/legende.js',
                '/js/nachlade.js',
                '/js/kalender-events.js',
                '/js/kalender-pitch.js',
                '/js/kalender-farbe.js',
                '/js/kalender-ansicht.js',
                '/js/kalender-titel.js',
                '/js/vermietung-hinweis.js',
                '/js/kalender.js',
            ],
        ]));
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
            'scripts' => [
                '/js/konflikte.js', '/js/filter.js', '/js/schreiben.js', '/js/offline.js', '/js/push.js',
                '/js/offline-events.js', '/js/offline-verfuegbarkeit.js', '/js/legende-gruppierung.js',
                '/js/legende.js', '/js/verfuegbarkeit.js',
            ],
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

    /**
     * Served dynamically (not a static public/ file) so the icons list can
     * point at the uploaded crest once one exists (issue #28).
     */
    public function manifest(Request $request): Response
    {
        $icons = $this->wappen->exists()
            ? [
                [
                    'src' => '/icon/icon-192.png?v=' . $this->wappen->version(),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
                [
                    'src' => '/icon/icon-512.png?v=' . $this->wappen->version(),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ]
            : [
                ['src' => '/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
            ];

        $manifest = [
            'name' => 'Vereinskalender',
            'short_name' => 'Kalender',
            'description' => 'Alle Termine des Vereins: Training, Spiele und Platzsperrungen',
            'start_url' => '/kalender',
            'display' => 'standalone',
            'background_color' => '#f4f6f4',
            'theme_color' => '#328551',
            'lang' => 'de',
            'icons' => $icons,
        ];

        return new Response(200, [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ], json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, string> $params
     */
    public function icon(Request $request, array $params): ResponseInterface
    {
        $path = $this->wappen->iconPath($params['name']);
        if ($path === null) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Icon nicht gefunden');
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return new Response(404, ['Content-Type' => 'text/plain; charset=utf-8'], 'Icon nicht gefunden');
        }

        return new Response(200, [
            'Content-Type' => 'image/png',
            // the URL carries ?v=<wappenVersion>, so immutable caching is
            // safe - a new upload gets a new URL (StaticFileHandler uses
            // the same pattern for versioned assets)
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], $bytes);
    }

    /**
     * Issue #37: Ziel für die 301-Redirects der Alt-Routen /belegung und
     * /spielplan - der Query-String (Filter) wandert unverändert mit.
     */
    private function kalenderZiel(Request $request): string
    {
        return '/kalender' . ($request->query !== [] ? '?' . http_build_query($request->query) : '');
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function stammdaten(): array
    {
        $teams = array_map(static fn(array $t): array => [
            'id' => (int) $t['id'],
            'bereich_id' => $t['bereich_id'] !== null ? (int) $t['bereich_id'] : null,
            'name' => (string) $t['name'],
            'kuerzel' => (string) $t['kuerzel'],
            'farbe' => (string) $t['farbe'],
            'aktiv' => (int) $t['aktiv'] === 1,
        ], $this->teams->findAll());

        $bereiche = array_map(static fn(array $b): array => [
            'id' => (int) $b['id'],
            'name' => (string) $b['name'],
            'kuerzel' => (string) $b['kuerzel'],
            'sortierung' => (int) $b['sortierung'],
        ], $this->bereiche->findAktive());

        $venues = array_map(static fn(array $v): array => [
            'id' => (int) $v['id'],
            'name' => (string) $v['name'],
            'farbe' => (string) $v['farbe'],
            'adresse' => (string) $v['adresse'],
        ], $this->venues->findAll());

        $pitches = array_map(static fn(array $p): array => [
            'id' => (int) $p['id'],
            'venue_id' => (int) $p['venue_id'],
            'sportheim_id' => $p['sportheim_id'] !== null ? (int) $p['sportheim_id'] : null,
            'name' => (string) $p['name'],
            'kuerzel' => (string) $p['kuerzel'],
            'farbe' => (string) $p['farbe'],
            'venue_name' => (string) ($p['venue_name'] ?? ''),
        ], $this->pitches->findAll());

        $sportheime = array_map(static fn(array $s): array => [
            'id' => (int) $s['id'],
            'venue_id' => (int) $s['venue_id'],
            'name' => (string) $s['name'],
        ], $this->sportheime->findAktive());

        $sportheimRaeume = array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'sportheim_id' => (int) $r['sportheim_id'],
            'name' => (string) $r['name'],
            'kuerzel' => (string) $r['kuerzel'],
        ], array_values(array_filter(
            $this->raeume->findAll(),
            static fn(array $r): bool => (int) $r['aktiv'] === 1,
        )));

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
                'bereiche' => $bereiche,
                'venues' => $venues,
                'pitches' => $pitches,
                'sportheime' => $sportheime,
                'sportheimRaeume' => $sportheimRaeume,
                'auswaertsFarbe' => $auswaertsFarbe,
            ],
            ':root { ' . implode(' ', $cssLines) . ' }',
        ];
    }
}
