<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\StatController;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Repository\UsageStatRepository;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * navigator.sendBeacon target (CLAUDE.md section 6): accepts only a fixed
 * whitelist of metric names. 'platzauswahl' (Issue #6) counts pitch-selector
 * usage in the Platzbelegung view; 'ansicht_*' (Issue #37) counts each of the
 * four Kalender-Darstellungen (Tag/Woche/Monat/Liste).
 */
final class StatControllerTest extends DatabaseTestCase
{
    public function testPlatzauswahlIsAcceptedAndCounted(): void
    {
        $stats = new UsageStatRepository($this->pdo());
        $controller = new StatController($stats);

        $response = $controller->beacon(new Request(HttpMethod::Post, '/api/stat', post: ['metrik' => 'platzauswahl']));

        self::assertSame(200, $response->status);
        self::assertSame(1, $stats->summary('feature_platzauswahl')['heute']);
    }

    /**
     * @return list<array{string}>
     */
    public static function ansichtMetrikenProvider(): array
    {
        return [['ansicht_tag'], ['ansicht_woche'], ['ansicht_monat'], ['ansicht_liste']];
    }

    #[DataProvider('ansichtMetrikenProvider')]
    public function testAnsichtMetrikenSindAkzeptiertUndGezaehlt(string $metrik): void
    {
        $stats = new UsageStatRepository($this->pdo());
        $controller = new StatController($stats);

        $response = $controller->beacon(new Request(HttpMethod::Post, '/api/stat', post: ['metrik' => $metrik]));

        self::assertSame(200, $response->status);
        self::assertSame(1, $stats->summary('feature_' . $metrik)['heute']);
    }

    public function testUnknownMetricIsRejected(): void
    {
        $controller = new StatController(new UsageStatRepository($this->pdo()));

        $response = $controller->beacon(new Request(HttpMethod::Post, '/api/stat', post: ['metrik' => 'unbekannt']));

        self::assertSame(422, $response->status);
    }
}
