<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Kalender\ConflictException;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Manually created matches (issue #12, CLAUDE.md section 3): public
 * create/edit/delete, marked import_source_id IS NULL. Conflict checking
 * mirrors BookingServiceTest's conventions ('gesperrt' blocks, everything
 * else warns); IcsImportTest carries the "import never touches manual
 * matches" regression.
 */
final class ManualMatchServiceTest extends DatabaseTestCase
{
    private int $venueId;
    private int $pitchId;
    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->venueId = $this->createVenue();
        $this->pitchId = $this->createPitch($this->venueId);
        $this->teamId = $this->createTeam();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function matchInput(array $overrides = []): array
    {
        return [
            'team_id' => (string) $this->teamId,
            'datum' => '2026-09-05',
            'anstoss' => '15:00',
            'gegner' => 'FC Freundschaft',
            'pitch_id' => (string) $this->pitchId,
            'ort_text' => '',
            ...$overrides,
        ];
    }

    private function weekdayOf(string $datum): int
    {
        return (int) new \DateTimeImmutable($datum)->format('N');
    }

    public function testCreateWritesEventAndProjection(): void
    {
        $result = $this->matchService()->createMatch($this->matchInput(), $this->context());

        self::assertSame([], $result['warnings']);
        $rows = $this->dumpTable('match');
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['import_source_id']);
        self::assertSame('', $rows[0]['ics_uid']);
        self::assertSame(1, (int) $rows[0]['heimspiel']);
        self::assertSame(1, (int) $rows[0]['pitch_manuell']);
        self::assertSame('geplant', $rows[0]['status']);
        self::assertSame('2026-09-05 15:00:00', $rows[0]['anstoss']);
        self::assertNull($rows[0]['ende']);
    }

    public function testTwoManualMatchesCoexist(): void
    {
        $this->matchService()->createMatch($this->matchInput(), $this->context());
        $this->matchService()->createMatch(
            $this->matchInput(['datum' => '2026-09-12', 'gegner' => 'FC Zweite']),
            $this->context(),
        );

        self::assertCount(2, $this->dumpTable('match'));
    }

    public function testMissingTeamIsRejected(): void
    {
        try {
            $this->matchService()->createMatch($this->matchInput(['team_id' => '0']), $this->context());
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('team_id', $e->getErrors());
        }
    }

    public function testMissingKickoffIsRejected(): void
    {
        try {
            $this->matchService()->createMatch($this->matchInput(['anstoss' => '']), $this->context());
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('anstoss', $e->getErrors());
        }
    }

    public function testMissingGegnerIsRejected(): void
    {
        try {
            $this->matchService()->createMatch($this->matchInput(['gegner' => '  ']), $this->context());
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('gegner', $e->getErrors());
        }
    }

    public function testMissingPitchAndOrtTextIsRejected(): void
    {
        try {
            $this->matchService()->createMatch($this->matchInput(['pitch_id' => '', 'ort_text' => '']), $this->context());
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ort_text', $e->getErrors());
        }
    }

    public function testEndeBeforeAnstossIsRejected(): void
    {
        try {
            $this->matchService()->createMatch($this->matchInput(['ende' => '14:00']), $this->context());
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ende', $e->getErrors());
        }
    }

    public function testHeimspielFromChosenPitch(): void
    {
        $this->matchService()->createMatch($this->matchInput(), $this->context());

        $row = $this->dumpTable('match')[0];
        self::assertSame(1, (int) $row['heimspiel']);
    }

    public function testHeimspielFromMatchingOrtText(): void
    {
        $this->createBegriff($this->venueId, 'Nachbarort');

        $this->matchService()->createMatch(
            $this->matchInput(['pitch_id' => '', 'ort_text' => 'Sportplatz Nachbarort']),
            $this->context(),
        );

        $row = $this->dumpTable('match')[0];
        self::assertSame(1, (int) $row['heimspiel']);
        self::assertSame(0, (int) $row['pitch_manuell']);
        self::assertNull($row['pitch_id']);
    }

    public function testAuswaertsspielWithoutMatchingOrtText(): void
    {
        $this->matchService()->createMatch(
            $this->matchInput(['pitch_id' => '', 'ort_text' => 'Sportplatz Irgendwo']),
            $this->context(),
        );

        $row = $this->dumpTable('match')[0];
        self::assertSame(0, (int) $row['heimspiel']);
    }

    public function testUpdateChangesFieldsAndBumpsSequence(): void
    {
        $matchService = $this->matchService();
        $result = $matchService->createMatch($this->matchInput(), $this->context());
        self::assertSame(0, (int) $this->dumpTable('match')[0]['ics_sequence']);

        $matchService->updateMatch(
            $result['id'],
            $this->matchInput(['gegner' => 'FC Neu', 'status' => 'geplant']),
            $this->context(),
        );

        $row = $this->dumpTable('match')[0];
        self::assertSame('FC Neu', $row['gegner']);
        self::assertSame(1, (int) $row['ics_sequence']);
    }

    public function testUpdateToAbgesagtSetsStatus(): void
    {
        $matchService = $this->matchService();
        $result = $matchService->createMatch($this->matchInput(), $this->context());

        $matchService->updateMatch($result['id'], $this->matchInput(['status' => 'abgesagt']), $this->context());

        self::assertSame('abgesagt', $this->dumpTable('match')[0]['status']);
    }

    public function testDeleteEmitsDeletedEventAndRemovesRow(): void
    {
        $matchService = $this->matchService();
        $result = $matchService->createMatch($this->matchInput(), $this->context());

        $matchService->deleteMatch($result['id'], $this->context());

        self::assertCount(0, $this->dumpTable('match'));
        $events = array_values(array_filter(
            $this->dumpTable('event'),
            static fn(array $e): bool => $e['aggregat_typ'] === 'match' && $e['event_typ'] === 'deleted',
        ));
        self::assertCount(1, $events);
    }

    public function testImportedMatchRejectedByUpdate(): void
    {
        $importSourceId = $this->createImportSource($this->teamId);
        $matchId = $this->createMatch($this->teamId, ['import_source_id' => $importSourceId, 'ics_uid' => 'abc']);

        try {
            $this->matchService()->updateMatch($matchId, $this->matchInput(), $this->context());
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testImportedMatchRejectedByDelete(): void
    {
        $importSourceId = $this->createImportSource($this->teamId);
        $matchId = $this->createMatch($this->teamId, ['import_source_id' => $importSourceId, 'ics_uid' => 'abc']);

        try {
            $this->matchService()->deleteMatch($matchId, $this->context());
            self::fail('Expected a validation error');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }

        self::assertCount(1, $this->dumpTable('match'), 'imported match must survive the rejected delete');
    }

    public function testConflictWithOverlappingSlotWarnsButStillWrites(): void
    {
        $datum = '2026-09-05';
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [$this->weekdayOf($datum)],
            'beginn' => '16:00',
            'ende' => '17:30',
            'gueltig_ab' => $datum,
            'gueltig_bis' => $datum,
        ], $this->context());

        $result = $this->matchService()->createMatch($this->matchInput(['datum' => $datum, 'anstoss' => '15:00']), $this->context());

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Doppelbelegung', $result['warnings'][0]);
        self::assertCount(1, $this->dumpTable('match'), 'a warning does not block the write');
    }

    public function testConflictWithOverlappingMatchWarns(): void
    {
        $this->matchService()->createMatch($this->matchInput(), $this->context());

        $otherTeam = $this->createTeam('E2');
        $result = $this->matchService()->createMatch(
            $this->matchInput(['team_id' => (string) $otherTeam, 'gegner' => 'FC Dritte']),
            $this->context(),
        );

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Spiel gegen FC Freundschaft', $result['warnings'][0]);
        self::assertCount(2, $this->dumpTable('match'));
    }

    public function testGesperrtRestrictionBlocksCreate(): void
    {
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-09-05 00:00:00',
            'bis' => '2026-09-05 23:59:00',
            'art' => 'gesperrt',
            'grund' => 'Platzpflege',
        ], $this->context());

        try {
            $this->matchService()->createMatch($this->matchInput(), $this->context());
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('gesperrt', $e->getConflicts()[0]);
        }

        self::assertCount(0, $this->dumpTable('match'), 'blocked match must not be saved');
    }

    public function testEingeschraenktRestrictionWarns(): void
    {
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-09-05 00:00:00',
            'bis' => '2026-09-05 23:59:00',
            'art' => 'eingeschraenkt',
            'grund' => 'Rasenschonung',
        ], $this->context());

        $result = $this->matchService()->createMatch($this->matchInput(), $this->context());

        self::assertNotSame([], $result['warnings']);
        self::assertCount(1, $this->dumpTable('match'));
    }

    public function testExplicitEndeShortensConflictWindow(): void
    {
        $datum = '2026-09-05';
        $this->bookingService()->createSlot([
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [$this->weekdayOf($datum)],
            'beginn' => '16:30',
            'ende' => '18:00',
            'gueltig_ab' => $datum,
            'gueltig_bis' => $datum,
        ], $this->context());

        // without an explicit ende, the +2h fallback (15:00-17:00) overlaps
        $ohneEnde = $this->matchService()->check($this->matchInput(['datum' => $datum, 'anstoss' => '15:00']), null);
        self::assertNotSame([], $ohneEnde->warnings);

        // an explicit ende of 16:00 no longer overlaps the 16:30 slot
        $matchService = $this->matchService();
        $result = $matchService->createMatch(
            $this->matchInput(['datum' => $datum, 'anstoss' => '15:00', 'ende' => '16:00']),
            $this->context(),
        );
        self::assertSame([], $result['warnings']);
        self::assertSame('2026-09-05 16:00:00', $this->dumpTable('match')[0]['ende']);
    }

    public function testUpdateIgnoresItsOwnOccupancyInConflictCheck(): void
    {
        $matchService = $this->matchService();
        $result = $matchService->createMatch($this->matchInput(), $this->context());

        // same time, same pitch, just a title change - must not warn about
        // colliding with itself
        $updateResult = $matchService->updateMatch($result['id'], $this->matchInput(['gegner' => 'FC Freundschaft II']), $this->context());

        self::assertSame([], $updateResult['warnings']);
    }

    public function testAbgesagtSkipsConflictCheck(): void
    {
        $matchService = $this->matchService();
        $result = $matchService->createMatch($this->matchInput(), $this->context());

        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-09-05 00:00:00',
            'bis' => '2026-09-05 23:59:00',
            'art' => 'gesperrt',
            'grund' => 'Platzpflege',
        ], $this->context());

        // a cancellation must go through even though the pitch is now
        // gesperrt for that day - the match no longer occupies it
        $updateResult = $matchService->updateMatch($result['id'], $this->matchInput(['status' => 'abgesagt']), $this->context());

        self::assertSame([], $updateResult['warnings']);
        self::assertSame('abgesagt', $this->dumpTable('match')[0]['status']);
    }
}
