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
        $stats->increment('seite', '/kalender');
        $stats->increment('seite', '/kalender');
        $stats->increment('seite', '/verfuegbarkeit');

        $summary = $stats->summary('seite');
        self::assertSame(3, $summary['heute']);
        self::assertSame(3, $summary['tage30']);

        $top = $stats->topDimensions('seite');
        self::assertSame('/kalender', $top[0]['dimension']);
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
        // Issue #27: migration 013 seeds system events before any test event,
        // so id=1 is no longer necessarily the team event - target it by
        // aggregat_typ instead of assuming a fixed id.
        $oldEventId = (int) $this->pdo()
            ->query("SELECT id FROM event WHERE aggregat_typ = 'team' ORDER BY id ASC LIMIT 1")
            ->fetchColumn();
        $this->pdo()->exec(sprintf(
            "UPDATE event SET erstellt_am = '2020-01-01 12:00:00' WHERE id = %d",
            $oldEventId,
        ));
        $this->createTeam('E2'); // recent event

        $anonymisiert = new EventHistoryRepository($this->pdo())->anonymizeOldIps(90);

        self::assertSame(1, $anonymisiert);
        $events = $this->pdo()
            ->query("SELECT * FROM event WHERE aggregat_typ = 'team' ORDER BY id")
            ->fetchAll();
        self::assertSame('', $events[0]['ip'], 'old event anonymised');
        self::assertNotSame('', $events[1]['ip'], 'recent event untouched');
    }

    public function testEventHistoryFilters(): void
    {
        $history = new EventHistoryRepository($this->pdo());
        // Issue #27: migration 013 seeds system events, so the unfiltered
        // total is no longer just this test's own events - compare a delta.
        $gesamtVorher = $history->search([])['gesamt'];

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

        self::assertSame($gesamtVorher + 3, $history->search([])['gesamt']);
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
        self::assertSame('[Vereinskalender] Import kaputt', $sent[0], 'Issue #62: subject prefix from the app_name setting default');

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
            'team_ids' => [$teamId],
            'pitch_id' => $pitchId,
            'wochentage' => [(int) new \DateTimeImmutable('today')->format('N')],
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => new \DateTimeImmutable('today')->format('Y-m-d'),
            'gueltig_bis' => new \DateTimeImmutable('+30 days')->format('Y-m-d'),
        ], $this->context());
        $this->createMatch($teamId, ['anstoss' => '2020-01-01 15:00:00']);
        $this->createMatch($teamId, ['anstoss' => '2099-01-01 15:00:00']);

        $pdo = $this->pdo();
        $bundle = new \App\Service\Kalender\OfflineBundleService(
            new \App\Repository\TrainingSlotRepository($pdo),
            new \App\Repository\SlotExceptionRepository($pdo),
            new \App\Repository\PitchRestrictionRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
            new \App\Repository\VenueRepository($pdo),
            new SettingRepository($pdo),
            \App\Service\Kalender\VenueMatcher::fromDatabase($pdo),
            new \App\Repository\BereichRepository($pdo),
            new \App\Repository\SportheimRepository($pdo),
            new \App\Repository\SportheimRaumRepository($pdo),
            new \App\Repository\VermietungRepository($pdo),
        )->build();

        self::assertSame(4, $bundle['format']);
        self::assertCount(2, $bundle['spiele'], 'the complete dataset: past AND future matches, no date window');
        self::assertArrayHasKey('team_farbe', $bundle['spiele'][0], 'both color modes work offline');
        self::assertArrayHasKey('venue_farbe', $bundle['spiele'][0]);
        self::assertArrayHasKey('pitch_farbe', $bundle['spiele'][0]);
        self::assertCount(1, $bundle['slots'], 'training slots as RULES, not expanded occurrences');
        self::assertSame([$teamId], $bundle['slots'][0]['team_ids']);
        self::assertSame([], $bundle['ausnahmen']);
        self::assertSame([], $bundle['sperrungen']);
        self::assertNotSame([], $bundle['teams']);
        self::assertNotSame([], $bundle['bereiche'], 'Issue #27: dynamic bereiche list instead of the fixed enum');
        self::assertSame('#0969da', $bundle['pitches'][0]['farbe']);
        self::assertNull($bundle['pitches'][0]['adresse']);
        self::assertSame('#57606a', $bundle['settings']['auswaerts_farbe']);
    }
}
