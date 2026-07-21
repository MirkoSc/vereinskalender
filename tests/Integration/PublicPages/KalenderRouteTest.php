<?php

declare(strict_types=1);

namespace App\Tests\Integration\PublicPages;

use App\Http\HttpMethod;
use App\Http\Request;
use App\PublicPages\PublicController;
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
use App\Tests\Support\DatabaseTestCase;
use App\View\View;

/**
 * Issue #37: Spielplan + Platzbelegung sind eine Seite (/kalender) mit vier
 * Darstellungen (Tag/Woche/Monat/Liste) geworden; die Alt-Routen /belegung
 * und /spielplan leiten (mit Query-String, damit geteilte Filter-Links
 * funktionieren) per 301 dorthin um, statt selbst zu rendern.
 */
final class KalenderRouteTest extends DatabaseTestCase
{
    private function controller(?string $publicDir = null): PublicController
    {
        $pdo = $this->pdo();

        return new PublicController(
            new View(dirname(__DIR__, 3) . '/app/views', '0.0.0-test'),
            new TeamRepository($pdo),
            new PitchRepository($pdo),
            new VenueRepository($pdo),
            new SettingRepository($pdo),
            new PageRepository($pdo),
            new UsageStatRepository($pdo),
            '0.0.0-test',
            $publicDir ?? sys_get_temp_dir(),
            new WappenService(sys_get_temp_dir()),
            new BereichRepository($pdo),
            new SportheimRepository($pdo),
            new SportheimRaumRepository($pdo),
        );
    }

    public function testKalenderSeiteRendertBeideEintragenWegeUndZaehltAlsSeite(): void
    {
        $response = $this->controller()->kalender(new Request(HttpMethod::Get, '/kalender'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('id="new-entry"', $response->body);
        self::assertStringContainsString('id="entry-booking"', $response->body);
        self::assertStringContainsString('id="entry-match"', $response->body);
        self::assertStringContainsString('id="booking-dialog"', $response->body);
        self::assertStringContainsString('id="match-dialog"', $response->body);
        self::assertMatchesRegularExpression('#<script type="application/json" id="app-data">#', $response->body);

        $stats = new UsageStatRepository($this->pdo());
        self::assertSame(1, $stats->summary('seite')['heute']);
        self::assertSame('/kalender', $stats->topDimensions('seite')[0]['dimension']);
    }

    public function testBelegungLeitetOhneQueryAufKalenderUm(): void
    {
        $response = $this->controller()->belegung(new Request(HttpMethod::Get, '/belegung'));

        self::assertSame(301, $response->status);
        self::assertSame('/kalender', $response->headers['Location']);
    }

    public function testBelegungLeitetMitQueryStringAufKalenderUm(): void
    {
        $response = $this->controller()->belegung(new Request(
            HttpMethod::Get,
            '/belegung',
            query: ['team' => '5', 'pitch' => '3'],
        ));

        self::assertSame(301, $response->status);
        self::assertSame('/kalender?team=5&pitch=3', $response->headers['Location']);
    }

    public function testSpielplanLeitetMitQueryStringAufKalenderUm(): void
    {
        $response = $this->controller()->spielplan(new Request(
            HttpMethod::Get,
            '/spielplan',
            query: ['bereich' => '2'],
        ));

        self::assertSame(301, $response->status);
        self::assertSame('/kalender?bereich=2', $response->headers['Location']);
    }

    public function testManifestStartUrlZeigtAufKalender(): void
    {
        $response = $this->controller()->manifest(new Request(HttpMethod::Get, '/manifest.webmanifest'));

        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('/kalender', $manifest['start_url']);
    }

    /**
     * Issue #62: manifest name/short_name come from the app_name(_kurz)
     * settings instead of the hardcoded "Vereinskalender"/"Kalender".
     */
    public function testManifestUsesConfiguredAppName(): void
    {
        $settings = new SettingRepository($this->pdo());
        $settings->set('app_name', 'SG Musterstadt');
        $settings->set('app_name_kurz', 'SGM');

        $response = $this->controller()->manifest(new Request(HttpMethod::Get, '/manifest.webmanifest'));
        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('SG Musterstadt', $manifest['name']);
        self::assertSame('SGM', $manifest['short_name']);
    }

    public function testManifestFallsBackToTruncatedAppNameForShortName(): void
    {
        $settings = new SettingRepository($this->pdo());
        $settings->set('app_name', 'Ein ziemlich langer Vereinsname');

        $response = $this->controller()->manifest(new Request(HttpMethod::Get, '/manifest.webmanifest'));
        $manifest = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Ein ziemlich langer Vereinsname', $manifest['name']);
        self::assertSame(mb_substr('Ein ziemlich langer Vereinsname', 0, 12), $manifest['short_name']);
    }

    /**
     * Issue #62: the service worker template gets the app name injected
     * next to the release version, used as the push-notification title
     * fallback.
     */
    public function testServiceWorkerInjectsConfiguredAppName(): void
    {
        $settings = new SettingRepository($this->pdo());
        $settings->set('app_name', 'SG "Muster" Stadt');

        $publicDir = dirname(__DIR__, 3) . '/public';
        $response = $this->controller($publicDir)->serviceWorker(new Request(HttpMethod::Get, '/sw.js'));

        self::assertStringContainsString('const APP_NAME = "SG \\"Muster\\" Stadt";', $response->body);
        self::assertStringNotContainsString('__APP_NAME__', $response->body);
    }
}
