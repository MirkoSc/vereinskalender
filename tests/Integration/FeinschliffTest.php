<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\EventHistoryRepository;
use App\Repository\SettingRepository;
use App\Repository\UsageStatRepository;
use App\Service\RateLimiter;
use App\Service\Stats\AlarmMailer;
use App\Tests\Support\DatabaseTestCase;

/**
 * Milestone 6 building blocks: rate limit, usage stats, IP anonymisation,
 * event history filters, alarm mail throttling.
 */
final class FeinschliffTest extends DatabaseTestCase
{
    public function testRateLimiterBlocksAboveThirtyPerMinute(): void
    {
        $limiter = new RateLimiter($this->pdo());

        for ($i = 0; $i < RateLimiter::LIMIT_PER_MINUTE; $i++) {
            self::assertTrue($limiter->allow('203.0.113.5'), 'request ' . ($i + 1) . ' allowed');
        }
        self::assertFalse($limiter->allow('203.0.113.5'), 'request 31 blocked');
        self::assertTrue($limiter->allow('203.0.113.99'), 'other IPs unaffected');
    }

    public function testUsageStatIncrementsAndAggregates(): void
    {
        $stats = new UsageStatRepository($this->pdo());
        $stats->increment('seite', '/belegung');
        $stats->increment('seite', '/belegung');
        $stats->increment('seite', '/spielplan');

        $summary = $stats->summary('seite');
        self::assertSame(3, $summary['heute']);
        self::assertSame(3, $summary['tage30']);

        $top = $stats->topDimensions('seite');
        self::assertSame('/belegung', $top[0]['dimension']);
        self::assertSame(2, $top[0]['anzahl']);

        // no IPs, no user agents anywhere in the table
        $columns = $this->pdo()
            ->query("SELECT column_name FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'usage_stat'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['datum', 'metrik', 'dimension', 'anzahl'], array_values($columns));
    }

    public function testIpAnonymisationOnlyAffectsOldEvents(): void
    {
        $this->createTeam('E1'); // fresh event with IP
        $this->pdo()->exec(
            "UPDATE event SET erstellt_am = '2020-01-01 12:00:00' WHERE id = 1",
        );
        $this->createTeam('E2'); // recent event

        $anonymisiert = new EventHistoryRepository($this->pdo())->anonymizeOldIps(90);

        self::assertSame(1, $anonymisiert);
        $events = $this->dumpTable('event');
        self::assertSame('', $events[0]['ip'], 'old event anonymised');
        self::assertNotSame('', $events[1]['ip'], 'recent event untouched');
    }

    public function testEventHistoryFilters(): void
    {
        $this->createTeam('A');
        $this->createVenue();
        $store = $this->eventStore();
        $store->append(
            \App\Domain\AggregateType::Team,
            null,
            \App\Domain\EventType::Created,
            ['bereich' => 'F', 'name' => 'F9', 'kuerzel' => 'F9', 'farbe' => '#cf222e', 'aktiv' => true, 'sortierung' => 0],
            new \App\Domain\EventContext('Fremder', '198.51.100.1', \App\Domain\EventSource::Web),
        );

        $history = new EventHistoryRepository($this->pdo());

        self::assertSame(3, $history->search([])['gesamt']);
        self::assertSame(2, $history->search(['aggregat_typ' => 'team'])['gesamt']);
        self::assertSame(1, $history->search(['ip' => '198.51.100.1'])['gesamt']);
        self::assertSame(1, $history->search(['editor' => 'Fremder'])['gesamt']);
        self::assertSame(1, $history->search(['quelle' => 'web'])['gesamt']);
        self::assertSame(0, $history->search(['nur_ausgeschlossen' => '1'])['gesamt']);
    }

    public function testAlarmMailerThrottlesPerTopicAndDay(): void
    {
        $sent = [];
        $settings = new SettingRepository($this->pdo());
        $settings->set('alarm_email', 'admin@example.test');
        $mailer = new AlarmMailer($settings, function (string $to, string $subject, string $body) use (&$sent): bool {
            $sent[] = $subject;

            return true;
        });

        $mailer->alert('importfehler', 'Import kaputt', 'Details');
        $mailer->alert('importfehler', 'Import immer noch kaputt', 'Details');
        $mailer->alert('updatefehler', 'Update kaputt', 'Details');

        self::assertCount(2, $sent, 'second import alert on the same day is throttled');

        // without a configured address nothing is sent
        $settings->set('alarm_email', '');
        $mailer->alert('anderes_thema', 'x', 'y');
        self::assertCount(2, $sent);
    }

    public function testMarkdownRendersSafelyEscaped(): void
    {
        $html = \App\Support\Markdown::toHtml(
            "# Titel\n\nAbsatz mit **fett** und [Link](https://example.test/a).\n\n- Punkt 1\n- Punkt 2\n\n<script>alert(1)</script>",
        );

        self::assertStringContainsString('<h2>Titel</h2>', $html);
        self::assertStringContainsString('<strong>fett</strong>', $html);
        self::assertStringContainsString('<a href="https://example.test/a" rel="noopener">Link</a>', $html);
        self::assertStringContainsString('<li>Punkt 1</li>', $html);
        self::assertStringNotContainsString('<script>', $html, 'HTML input is escaped');
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testOfflineBundleContainsEverythingForOfflineRendering(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $teamId = $this->createTeam();
        $this->bookingService()->createSlot([
            'team_id' => $teamId,
            'pitch_id' => $pitchId,
            'wochentag' => (int) new \DateTimeImmutable('today')->format('N'),
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => new \DateTimeImmutable('today')->format('Y-m-d'),
            'gueltig_bis' => new \DateTimeImmutable('+30 days')->format('Y-m-d'),
        ], $this->context());

        $pdo = $this->pdo();
        $bundle = new \App\Service\Kalender\OfflineBundleService(
            $this->eventFeedService(),
            $this->availabilityService(),
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\VenueRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
            new SettingRepository($pdo),
        )->build();

        self::assertSame(new \DateTimeImmutable('today')->format('Y-m-d'), $bundle['von']);
        self::assertNotSame([], $bundle['events'], 'the slot occurrence is inside the 7-day window');
        self::assertArrayHasKey('team_farbe', $bundle['events'][0], 'both color modes work offline');
        self::assertArrayHasKey('venue_farbe', $bundle['events'][0]);
        self::assertNotSame([], $bundle['teams']);
        self::assertNotSame([], $bundle['verfuegbarkeit']['venues']);
        self::assertSame('#57606a', $bundle['settings']['auswaerts_farbe']);
    }
}
