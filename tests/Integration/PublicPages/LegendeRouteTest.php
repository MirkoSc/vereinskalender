<?php

declare(strict_types=1);

namespace App\Tests\Integration\PublicPages;

use App\Domain\AggregateType;
use App\Domain\EventType;
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
 * Issue #38: /legende - wie Startseite und Kalender-Overlay - rendert keine
 * Namen/Farben serverseitig als eigenes HTML, sondern bettet dieselbe
 * appData ein wie die Kalenderansichten (PublicController::stammdaten()).
 * public/js/legende.js baut daraus die Legende clientseitig; das hält die
 * Daten an einer einzigen Stelle gepflegt (CLAUDE.md Abschnitt 8/Issue #38)
 * und macht die Seite ohne weiteren Request offline nutzbar.
 */
final class LegendeRouteTest extends DatabaseTestCase
{
    private function controller(): PublicController
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
            sys_get_temp_dir(),
            new WappenService(sys_get_temp_dir()),
            new BereichRepository($pdo),
            new SportheimRepository($pdo),
            new SportheimRaumRepository($pdo),
        );
    }

    public function testLegendeSeiteBettetAppDataMitTeamsVenuesPitchesUndAuswaertsfarbeEin(): void
    {
        $venueId = $this->createVenue('Sportplatz Nord', 'Sportweg 1');
        $this->createPitch($venueId, 'Platz A', '#0969da', 'A');
        $bereichId = $this->createBereich('E-Jugend', 'E', 10);
        $this->eventStore()->append(
            AggregateType::Team,
            null,
            EventType::Created,
            ['bereich' => 'E', 'bereich_id' => $bereichId, 'name' => 'E2', 'kuerzel' => 'E2', 'farbe' => '#a82d24', 'aktiv' => true, 'sortierung' => 0],
            $this->context(),
        );

        $response = $this->controller()->legende(new Request(HttpMethod::Get, '/legende'));

        self::assertSame(200, $response->status);
        self::assertMatchesRegularExpression('/<div class="legende" data-legende><\/div>/', $response->body);

        preg_match('#<script type="application/json" id="app-data">(.*?)</script>#s', $response->body, $matches);
        self::assertArrayHasKey(1, $matches, 'app-data script missing from /legende response');
        $appData = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('Sportplatz Nord', $appData['venues'][0]['name']);
        self::assertSame('A', $appData['pitches'][0]['kuerzel']);
        self::assertSame('E-Jugend', $appData['bereiche'][0]['name']);
        self::assertSame('E2', $appData['teams'][0]['kuerzel']);
        self::assertTrue($appData['teams'][0]['aktiv']);
        self::assertSame('#57606a', $appData['auswaertsFarbe']);
    }
}
