<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\PitchRestrictionRepository;
use App\Service\Kalender\ConflictException;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #64: pitch_restriction is a public Ebene-2 write path, exactly like
 * manual matches (ManualMatchServiceTest) and Vermietungen
 * (VermietungServiceTest) - create/update/delete as events, delete = delete
 * event. Additionally covers the two behaviors specific to a restriction:
 * an art change takes effect on the very next conflict check (BookingService
 * reads pitch_restriction live), and it never invalidates bookings that
 * already exist within its range - occurrencesOnPitch()/"betroffene" surface
 * those as a hint instead.
 */
final class RestrictionServiceTest extends DatabaseTestCase
{
    private int $pitchId;

    protected function setUp(): void
    {
        parent::setUp();
        $venueId = $this->createVenue();
        $this->pitchId = $this->createPitch($venueId);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return [
            'pitch_id' => (string) $this->pitchId,
            'von' => '2026-09-05T00:00',
            'bis' => '2026-09-06T00:00',
            'art' => 'gesperrt',
            'grund' => 'Platzpflege',
            ...$overrides,
        ];
    }

    public function testCreateWritesEventAndProjection(): void
    {
        $result = $this->restrictionService()->create($this->input(), $this->context('Anna'));

        self::assertSame([], $result['betroffene']);
        $row = new PitchRestrictionRepository($this->pdo())->find($result['id']);
        self::assertNotNull($row);
        self::assertSame($this->pitchId, (int) $row['pitch_id']);
        self::assertSame('gesperrt', $row['art']);
        self::assertSame('Platzpflege', $row['grund']);

        $event = $this->pdo()
            ->query(sprintf(
                'SELECT * FROM event WHERE aggregat_typ = "pitch_restriction" AND aggregat_id = %d ORDER BY id',
                $result['id'],
            ))
            ->fetchAll();
        self::assertCount(1, $event);
        self::assertSame('created', $event[0]['event_typ']);
        self::assertSame('Anna', $event[0]['editor_name']);
    }

    public function testUpdateChangesProjectionAndWritesUpdatedEvent(): void
    {
        $id = $this->createRestriction($this->pitchId);

        $result = $this->restrictionService()->update($id, $this->input([
            'art' => 'eingeschraenkt',
            'grund' => 'Rasenschonung',
            'von' => '2026-09-10T08:00',
            'bis' => '2026-09-10T18:00',
        ]), $this->context());

        self::assertSame([], $result['betroffene']);
        $row = new PitchRestrictionRepository($this->pdo())->find($id);
        self::assertSame('eingeschraenkt', $row['art']);
        self::assertSame('Rasenschonung', $row['grund']);
        self::assertSame('2026-09-10 08:00:00', $row['von']);

        self::assertCount(1, $this->dumpTable('pitch_restriction'), 'update stays one row (upsert), no duplicate');

        $events = $this->pdo()
            ->query(sprintf(
                'SELECT event_typ FROM event WHERE aggregat_typ = "pitch_restriction" AND aggregat_id = %d ORDER BY id',
                $id,
            ))
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['created', 'updated'], $events);
    }

    public function testUpdateCanMoveToAnotherPitch(): void
    {
        $id = $this->createRestriction($this->pitchId);
        $otherPitchId = $this->createPitch($this->createVenue('Anderer Verein'), 'Platz 2');

        $this->restrictionService()->update($id, $this->input(['pitch_id' => (string) $otherPitchId]), $this->context());

        $row = new PitchRestrictionRepository($this->pdo())->find($id);
        self::assertSame($otherPitchId, (int) $row['pitch_id']);
    }

    public function testUpdateRejectsUnknownId(): void
    {
        try {
            $this->restrictionService()->update(999999, $this->input(), $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testUpdateRejectsMissingGrund(): void
    {
        $id = $this->createRestriction($this->pitchId);

        try {
            $this->restrictionService()->update($id, $this->input(['grund' => '']), $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('grund', $e->getErrors());
        }
    }

    public function testDeleteRemovesProjectionRowAndWritesDeleteEvent(): void
    {
        $id = $this->createRestriction($this->pitchId);

        $this->restrictionService()->delete($id, $this->context());

        self::assertNull(new PitchRestrictionRepository($this->pdo())->find($id));

        $event = $this->pdo()
            ->query(sprintf(
                'SELECT * FROM event WHERE aggregat_typ = "pitch_restriction" AND aggregat_id = %d ORDER BY id DESC LIMIT 1',
                $id,
            ))
            ->fetch();
        self::assertSame('deleted', $event['event_typ']);
    }

    public function testDeleteRejectsUnknownId(): void
    {
        try {
            $this->restrictionService()->delete(999999, $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }
    }

    /**
     * The core behavior of Issue #64: tightening the art takes effect
     * immediately - BookingService reads pitch_restriction live, no caching
     * anywhere in between.
     */
    public function testArtChangeAffectsConflictCheckImmediately(): void
    {
        $slotInput = [
            'team_ids' => [$this->createTeam()],
            'pitch_id' => $this->pitchId,
            'wochentage' => [(int) new \DateTimeImmutable('2026-09-05')->format('N')], // Samstag
            'beginn' => '10:00',
            'ende' => '11:30',
            'gueltig_ab' => '2026-09-05',
            'gueltig_bis' => '2026-09-05',
        ];

        $id = $this->createRestriction($this->pitchId, [
            'art' => 'eingeschraenkt',
            'von' => '2026-09-01 00:00:00',
            'bis' => '2026-09-30 00:00:00',
        ]);

        // 'eingeschraenkt' only warns - the booking is saved
        $result = $this->bookingService()->createSlot($slotInput, $this->context());
        self::assertNotSame([], $result['warnings']);
        self::assertCount(1, $this->dumpTable('training_slot'));

        // tightening to 'gesperrt' blocks the very next check
        $this->restrictionService()->update($id, $this->input([
            'art' => 'gesperrt',
            'von' => '2026-09-01T00:00',
            'bis' => '2026-09-30T00:00',
        ]), $this->context());

        try {
            // a different weekday (2026-09-09 is a Wednesday, the first slot
            // is a Saturday) so this attempt cannot collide with the
            // existing slot itself - only the tightened restriction should
            // block it
            $this->bookingService()->createSlot([
                ...$slotInput,
                'team_ids' => [$this->createTeam('E2')],
                'gueltig_ab' => '2026-09-09',
                'gueltig_bis' => '2026-09-09',
                'wochentage' => [(int) new \DateTimeImmutable('2026-09-09')->format('N')],
            ], $this->context());
            self::fail('Expected a conflict after tightening to gesperrt');
        } catch (ConflictException $e) {
            self::assertStringContainsString('gesperrt', $e->getConflicts()[0]);
        }
    }

    /**
     * Tightening must not touch bookings that were already saved before the
     * restriction existed/was tightened (CLAUDE.md section 4).
     */
    public function testTighteningDoesNotInvalidateExistingBooking(): void
    {
        $teamId = $this->createTeam();
        $this->bookingService()->createSlot([
            'team_ids' => [$teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [(int) new \DateTimeImmutable('2026-09-05')->format('N')],
            'beginn' => '10:00',
            'ende' => '11:30',
            'gueltig_ab' => '2026-09-05',
            'gueltig_bis' => '2026-09-05',
        ], $this->context());
        self::assertCount(1, $this->dumpTable('training_slot'));

        $this->restrictionService()->create($this->input(['art' => 'gesperrt']), $this->context());

        self::assertCount(1, $this->dumpTable('training_slot'), 'the earlier booking must survive the tightening');
    }

    public function testCreateReturnsBetroffeneForOverlappingSlotAndMatch(): void
    {
        $teamId = $this->createTeam('E2');
        $this->bookingService()->createSlot([
            'team_ids' => [$teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [(int) new \DateTimeImmutable('2026-09-05')->format('N')],
            'beginn' => '10:00',
            'ende' => '11:30',
            'gueltig_ab' => '2026-09-05',
            'gueltig_bis' => '2026-09-05',
        ], $this->context());
        $this->createMatch($teamId, [
            'anstoss' => '2026-09-05 15:00:00',
            'heimspiel' => true,
            'pitch_id' => $this->pitchId,
            'gegner' => 'FC Betroffen',
        ]);

        $result = $this->restrictionService()->create($this->input([
            'von' => '2026-09-05T00:00',
            'bis' => '2026-09-05T23:59',
        ]), $this->context());

        self::assertCount(2, $result['betroffene']);
        $joined = implode(' ', $result['betroffene']);
        self::assertStringContainsString('E2', $joined);
        self::assertStringContainsString('FC Betroffen', $joined);
    }

    public function testUpdateReturnsBetroffeneWhenArtTightensOverExistingBooking(): void
    {
        $teamId = $this->createTeam();
        $this->bookingService()->createSlot([
            'team_ids' => [$teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [(int) new \DateTimeImmutable('2026-09-05')->format('N')],
            'beginn' => '10:00',
            'ende' => '11:30',
            'gueltig_ab' => '2026-09-05',
            'gueltig_bis' => '2026-09-05',
        ], $this->context());

        $id = $this->createRestriction($this->pitchId, [
            'art' => 'eingeschraenkt',
            'von' => '2026-09-05 00:00:00',
            'bis' => '2026-09-05 23:59:00',
        ]);

        $result = $this->restrictionService()->update($id, $this->input([
            'art' => 'gesperrt',
            'von' => '2026-09-05T00:00',
            'bis' => '2026-09-05T23:59',
        ]), $this->context());

        self::assertCount(1, $result['betroffene']);
        self::assertStringContainsString('Belegung', $result['betroffene'][0]);
    }

    public function testReplayAfterUpdateProducesIdenticalProjection(): void
    {
        $id = $this->createRestriction($this->pitchId, ['art' => 'eingeschraenkt', 'grund' => 'Alt']);
        $this->restrictionService()->update($id, $this->input(['art' => 'gesperrt', 'grund' => 'Neu']), $this->context());

        $before = $this->dumpTable('pitch_restriction');

        $state = $this->runRebuildToCompletion($this->rebuildService());

        self::assertSame([], $state->skipped);
        self::assertSame($before, $this->dumpTable('pitch_restriction'));
    }
}
