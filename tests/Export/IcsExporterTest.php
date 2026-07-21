<?php

declare(strict_types=1);

namespace App\Tests\Export;

use App\Tests\Support\DatabaseTestCase;

final class IcsExporterTest extends DatabaseTestCase
{
    private function exporter(): \App\Service\Export\IcsExporter
    {
        $pdo = $this->pdo();

        // fixed horizon so the assertions are independent of the test date
        return new \App\Service\Export\IcsExporter(
            new \App\Repository\TeamRepository($pdo),
            new \App\Repository\MatchRepository($pdo),
            new \App\Repository\TrainingSlotRepository($pdo),
            new \App\Repository\SlotExceptionRepository($pdo),
            new \App\Repository\PitchRepository($pdo),
            new \App\Repository\SettingRepository($pdo),
            '2026-10-01',
            '2099-12-31',
        );
    }

    public function testMatchFeedHasStableUidsAndCancelledStatus(): void
    {
        $teamId = $this->createTeam('E1');
        $matchId = $this->createMatch($teamId, [
            'anstoss' => '2099-08-08 15:00:00',
            'gegner' => 'FC Gegner; Sonderzeichen, Test',
        ]);
        $cancelledId = $this->createMatch($teamId, [
            'anstoss' => '2099-08-15 15:00:00',
            'gegner' => 'FC Ausfall',
            'status' => 'abgesagt',
        ]);

        $ics = $this->exporter()->matchesFeed($teamId);

        self::assertStringContainsString('BEGIN:VCALENDAR', $ics);
        // stable UID from aggregat_id: relocations move instead of duplicating
        self::assertStringContainsString('UID:match-' . $matchId . '@vereinskalender', $ics);
        self::assertStringContainsString('UID:match-' . $cancelledId . '@vereinskalender', $ics);
        self::assertStringContainsString('STATUS:CANCELLED', $ics);
        self::assertStringContainsString('SUMMARY:E1: FC Gegner\; Sonderzeichen\, Test', $ics);
        self::assertStringContainsString('X-PUBLISHED-TTL:PT6H', $ics);
        // August = CEST: 15:00 Berlin = 13:00 UTC
        self::assertStringContainsString('DTSTART:20990808T130000Z', $ics);
    }

    /**
     * Issue #65: a bye stays in the feed (relevant to subscribers) with a
     * canonical title independent of the feed's own SUMMARY wording, and
     * without a LOCATION line - it never had one.
     */
    public function testSpielfreiMatchGetsCanonicalTitleAndNoLocation(): void
    {
        $teamId = $this->createTeam('E1');
        $this->createMatch($teamId, [
            'anstoss' => '2099-08-08 15:00:00',
            'gegner' => 'Ausfall (Feiertag)',
            'ort_text' => '',
            'spielfrei' => true,
        ]);

        $ics = $this->exporter()->matchesFeed($teamId);

        self::assertStringContainsString('SUMMARY:E1: Spielfrei', $ics);
        self::assertStringNotContainsString('LOCATION:', $ics);
    }

    public function testCalendarNameIncludesTheConfiguredAppName(): void
    {
        $settings = new \App\Repository\SettingRepository($this->pdo());
        $settings->set('app_name', 'SG Musterstadt');
        $teamId = $this->createTeam('E1');
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);

        $matchFeed = $this->exporter()->matchesFeed($teamId);
        $pitchFeed = $this->exporter()->pitchFeed($pitchId);

        self::assertStringContainsString('X-WR-CALNAME:SG Musterstadt: Spielplan E1', $matchFeed);
        self::assertStringContainsString('X-WR-CALNAME:SG Musterstadt: Belegung', $pitchFeed);
    }

    public function testMatchFeedUsesExplicitEndeWithTwoHourFallback(): void
    {
        $teamId = $this->createTeam('E1');
        // manual tournament with explicit end (Issue #12)
        $this->createMatch($teamId, [
            'anstoss' => '2099-08-08 10:00:00',
            'ende' => '2099-08-08 16:00:00',
            'gegner' => 'Turnier',
        ]);
        // imported-style match without: kickoff + 2h
        $this->createMatch($teamId, [
            'anstoss' => '2099-08-15 15:00:00',
            'gegner' => 'FC Gegner',
        ]);

        $ics = $this->exporter()->matchesFeed($teamId);

        // August = CEST (UTC+2)
        self::assertStringContainsString('DTEND:20990808T140000Z', $ics, 'explicit ende 16:00 local');
        self::assertStringContainsString('DTEND:20990815T150000Z', $ics, 'fallback 17:00 local');
    }

    /**
     * Mandatory (CLAUDE.md section 12): the export must be correct across
     * both DST transitions - same wall time, different UTC offset.
     */
    public function testPitchFeedIsDstCorrect(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $teamId = $this->createTeam('E1');

        // Sundays 19:00 local across the fall transition (2026-10-25).
        // Deliberately the pre-migration-008 payload shape (team_id/
        // wochentag): the projector must upgrade it to the list format.
        $this->eventStore()->append(
            \App\Domain\AggregateType::TrainingSlot,
            null,
            \App\Domain\EventType::Created,
            [
                'team_id' => $teamId,
                'pitch_id' => $pitchId,
                'wochentag' => 7,
                'beginn' => '19:00:00',
                'ende' => '20:30:00',
                'gueltig_ab' => '2026-10-18',
                'gueltig_bis' => '2026-11-01',
            ],
            $this->context(),
        );

        $ics = $this->exporter()->pitchFeed($pitchId);

        // CEST (UTC+2): 19:00 local = 17:00Z; CET (UTC+1): 19:00 local = 18:00Z
        self::assertStringContainsString('DTSTART:20261018T170000Z', $ics);
        self::assertStringContainsString('DTSTART:20261025T180000Z', $ics);
        self::assertStringContainsString('DTSTART:20261101T180000Z', $ics);
        self::assertStringContainsString('UID:slot-', $ics);
        self::assertStringContainsString('SUMMARY:Training E1', $ics);
    }
}
