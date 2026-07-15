<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Api\StatController;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Repository\UsageStatRepository;
use App\Tests\Support\DatabaseTestCase;

/**
 * navigator.sendBeacon target (CLAUDE.md section 6): accepts only a fixed
 * whitelist of metric names. 'platzauswahl' (Issue #6) counts pitch-selector
 * usage in the Platzbelegung view.
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

    public function testUnknownMetricIsRejected(): void
    {
        $controller = new StatController(new UsageStatRepository($this->pdo()));

        $response = $controller->beacon(new Request(HttpMethod::Post, '/api/stat', post: ['metrik' => 'unbekannt']));

        self::assertSame(422, $response->status);
    }
}
