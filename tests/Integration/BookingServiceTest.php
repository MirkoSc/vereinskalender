<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Domain\EventType;
use App\Service\Kalender\ConflictException;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Mandatory conflict check tests (CLAUDE.md section 12): 'gesperrt' blocks,
 * 'eingeschraenkt' allows with a warning, overlapping bookings collide.
 * Plus multi-team/multi-weekday slots and the three edit scopes.
 */
final class BookingServiceTest extends DatabaseTestCase
{
    private int $teamId;
    private int $pitchId;

    protected function setUp(): void
    {
        parent::setUp();
        $venueId = $this->createVenue();
        $this->pitchId = $this->createPitch($venueId);
        $this->teamId = $this->createTeam();
    }

    /**
     * @return array<string, mixed>
     */
    private function slotInput(array $overrides = []): array
    {
        return [
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'wochentage' => [2], // Dienstag
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-10-31',
            ...$overrides,
        ];
    }

    public function testCreateSlotWritesEventAndProjection(): void
    {
        $result = $this->bookingService()->createSlot($this->slotInput(), $this->context());

        self::assertSame([], $result['warnings']);
        $slots = $this->dumpTable('training_slot');
        self::assertCount(1, $slots);
        self::assertSame('19:00:00', $slots[0]['beginn']);
        self::assertSame([$this->teamId], json_decode((string) $slots[0]['team_ids'], true));
        self::assertSame([2], json_decode((string) $slots[0]['wochentage'], true));
    }

    public function testJointTrainingStoresTeamListAndLegacyFirstTeam(): void
    {
        $team2 = $this->createTeam('E2');

        $this->bookingService()->createSlot(
            $this->slotInput(['team_ids' => [$this->teamId, $team2]]),
            $this->context(),
        );

        $slot = $this->dumpTable('training_slot')[0];
        self::assertSame([$this->teamId, $team2], json_decode((string) $slot['team_ids'], true));
        self::assertSame($this->teamId, (int) $slot['team_id'], 'legacy column keeps the first team (rollback compat)');
    }

    public function testLegacySingleValuePayloadStillProjects(): void
    {
        // events written before migration 008 carry team_id/wochentag
        $this->eventStore()->append(AggregateType::TrainingSlot, null, EventType::Created, [
            'team_id' => $this->teamId,
            'pitch_id' => $this->pitchId,
            'wochentag' => 2,
            'beginn' => '19:00:00',
            'ende' => '20:30:00',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-10-31',
        ], $this->context());

        $slot = $this->dumpTable('training_slot')[0];
        self::assertSame([$this->teamId], json_decode((string) $slot['team_ids'], true));
        self::assertSame([2], json_decode((string) $slot['wochentage'], true));

        // and the replay produces the identical projection
        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($slot, $this->dumpTable('training_slot')[0]);
    }

    public function testOverlappingSlotOnSamePitchIsRejected(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        $otherTeam = $this->createTeam('E2');

        try {
            $booking->createSlot(
                $this->slotInput(['team_ids' => [$otherTeam], 'beginn' => '20:00', 'ende' => '21:30']),
                $this->context(),
            );
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Kollidiert', $e->getConflicts()[0]);
        }

        self::assertCount(1, $this->dumpTable('training_slot'), 'conflicting slot must not be saved');
    }

    public function testMultiWeekdaySlotConflictsOnEveryWeekday(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(['wochentage' => [2, 4]]), $this->context());

        try {
            // Thursday only, same time: collides with the Thursday leg
            $booking->createSlot(
                $this->slotInput(['team_ids' => [$this->createTeam('E2')], 'wochentage' => [4]]),
                $this->context(),
            );
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Kollidiert', $e->getConflicts()[0]);
        }
    }

    public function testAdjacentSlotDoesNotConflict(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        // 20:30–22:00 directly after 19:00–20:30: no overlap
        $result = $booking->createSlot(
            $this->slotInput(['team_ids' => [$this->createTeam('E2')], 'beginn' => '20:30', 'ende' => '22:00']),
            $this->context(),
        );

        self::assertSame([], $result['warnings']);
    }

    public function testSamePitchOtherWeekdayDoesNotConflict(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        $result = $booking->createSlot($this->slotInput(['wochentage' => [4]]), $this->context());

        self::assertSame([], $result['warnings']);
    }

    public function testGesperrtRestrictionBlocksBooking(): void
    {
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-08-04 00:00',
            'bis' => '2026-08-05 00:00',
            'art' => 'gesperrt',
            'grund' => 'Platzpflege',
        ], $this->context());

        try {
            $this->bookingService()->createSlot($this->slotInput(), $this->context());
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('gesperrt', $e->getConflicts()[0]);
            self::assertStringContainsString('Platzpflege', $e->getConflicts()[0]);
        }
    }

    public function testEingeschraenktAllowsBookingWithWarning(): void
    {
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-08-04 00:00',
            'bis' => '2026-08-05 00:00',
            'art' => 'eingeschraenkt',
            'grund' => 'Rasen frisch gesät',
        ], $this->context());

        $result = $this->bookingService()->createSlot($this->slotInput(), $this->context());

        self::assertCount(1, $result['warnings']);
        self::assertStringContainsString('Rasen frisch gesät', $result['warnings'][0]);
        self::assertCount(1, $this->dumpTable('training_slot'), 'booking IS saved despite the warning');
    }

    public function testMatchOnSamePitchConflicts(): void
    {
        // Saturday 2026-08-08 15:00 on this pitch, assumed 2h duration
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'heimspiel' => true,
            'pitch_id' => $this->pitchId,
        ]);

        try {
            $this->bookingService()->createSlot(
                $this->slotInput(['wochentage' => [6], 'beginn' => '16:00', 'ende' => '17:30']),
                $this->context(),
            );
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Spiel gegen FC Gegner', $e->getConflicts()[0]);
        }
    }

    public function testMatchWithExplicitEndeShortensAndExtendsConflictWindow(): void
    {
        // Saturday tournament 10:00-16:00 (Issue #12): the conflict window
        // must follow the explicit ende, not the +2h fallback
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 10:00:00',
            'ende' => '2026-08-08 16:00:00',
            'gegner' => 'Turnier',
            'heimspiel' => true,
            'pitch_id' => $this->pitchId,
        ]);

        // 13:00-14:30 lies past the +2h fallback but inside the explicit end
        try {
            $this->bookingService()->createSlot(
                $this->slotInput(['wochentage' => [6], 'beginn' => '13:00', 'ende' => '14:30']),
                $this->context(),
            );
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Spiel gegen Turnier', $e->getConflicts()[0]);
        }

        // 16:00-17:30 starts exactly at the explicit end: free
        $result = $this->bookingService()->createSlot(
            $this->slotInput(['wochentage' => [6], 'beginn' => '16:00', 'ende' => '17:30']),
            $this->context(),
        );
        self::assertSame([], $result['warnings']);
    }

    public function testConflictIgnoresOccurrencesRemovedByException(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot(
            $this->slotInput(['gueltig_ab' => '2026-08-04', 'gueltig_bis' => '2026-08-04']),
            $this->context(),
        );
        $booking->addException($created['id'], ['datum' => '2026-08-04', 'grund' => 'Ferien'], $this->context());

        // same time, same pitch: the only occurrence is cancelled => free
        $result = $booking->createSlot(
            $this->slotInput([
                'team_ids' => [$this->createTeam('E2')],
                'gueltig_ab' => '2026-08-04',
                'gueltig_bis' => '2026-08-04',
            ]),
            $this->context(),
        );

        self::assertSame([], $result['warnings']);
    }

    public function testUpdateSlotIgnoresItselfInConflictCheck(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        // shift by 30 minutes: overlaps its own old occurrences, must pass
        $result = $booking->updateSlot($created['id'], $this->slotInput(['beginn' => '19:30', 'ende' => '21:00']), $this->context());

        self::assertSame($created['id'], $result['id']);
        self::assertSame('19:30:00', $this->dumpTable('training_slot')[0]['beginn']);
    }

    public function testEditScopeNachfolgendeSplitsSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        // 2026-09-01 is a Tuesday occurrence; everything from there moves
        $result = $booking->updateSlot($created['id'], $this->slotInput([
            'edit_scope' => 'nachfolgende',
            'datum' => '2026-09-01',
            'beginn' => '18:00',
            'ende' => '19:30',
        ]), $this->context());

        self::assertNotSame($created['id'], $result['id'], 'continuation is a new slot');
        $slots = $this->dumpTable('training_slot');
        self::assertCount(2, $slots);
        self::assertSame('2026-08-31', $slots[0]['gueltig_bis'], 'old series ends the day before');
        self::assertSame('19:00:00', $slots[0]['beginn'], 'old occurrences keep their time');
        self::assertSame('2026-09-01', $slots[1]['gueltig_ab']);
        self::assertSame('2026-10-31', $slots[1]['gueltig_bis']);
        self::assertSame('18:00:00', $slots[1]['beginn']);
    }

    public function testEditScopeNachfolgendeAtFirstOccurrenceUpdatesWholeSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        // 2026-08-04 is the FIRST occurrence: no split, no empty stub
        $result = $booking->updateSlot($created['id'], $this->slotInput([
            'edit_scope' => 'nachfolgende',
            'datum' => '2026-08-04',
            'beginn' => '18:00',
            'ende' => '19:30',
        ]), $this->context());

        self::assertSame($created['id'], $result['id']);
        $slots = $this->dumpTable('training_slot');
        self::assertCount(1, $slots);
        self::assertSame('18:00:00', $slots[0]['beginn']);
        self::assertSame('2026-08-04', $slots[0]['gueltig_ab']);
    }

    public function testEditScopeEinzelnReplacesOneOccurrence(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        // move the 2026-08-11 training to Wednesday with a new time
        $result = $booking->updateSlot($created['id'], [
            'edit_scope' => 'einzeln',
            'datum' => '2026-08-11',
            'datum_neu' => '2026-08-12',
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'beginn' => '18:00',
            'ende' => '19:00',
        ], $this->context());

        self::assertNotSame($created['id'], $result['id']);

        $exceptions = $this->dumpTable('slot_exception');
        self::assertCount(1, $exceptions);
        self::assertSame($created['id'], (int) $exceptions[0]['slot_id']);
        self::assertSame('2026-08-11', $exceptions[0]['datum']);

        $slots = $this->dumpTable('training_slot');
        self::assertCount(2, $slots);
        self::assertSame('2026-08-12', $slots[1]['gueltig_ab']);
        self::assertSame('2026-08-12', $slots[1]['gueltig_bis']);
        self::assertSame([3], json_decode((string) $slots[1]['wochentage'], true), 'weekday follows the new date');
    }

    public function testEditScopeEinzelnAllowsTimeChangeOnSameDate(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        // overlaps the original 19:00–20:30 occurrence, which is freed by
        // its exception in the same write
        $result = $booking->updateSlot($created['id'], [
            'edit_scope' => 'einzeln',
            'datum' => '2026-08-11',
            'datum_neu' => '2026-08-11',
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'beginn' => '19:30',
            'ende' => '21:00',
        ], $this->context());

        self::assertNotSame($created['id'], $result['id']);
        self::assertCount(2, $this->dumpTable('training_slot'));
    }

    public function testEditScopeEinzelnDetectsConflictWithOwnSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(['wochentage' => [2, 4]]), $this->context());

        try {
            // moving the Tuesday occurrence onto the (still busy) Thursday
            $booking->updateSlot($created['id'], [
                'edit_scope' => 'einzeln',
                'datum' => '2026-08-04',
                'datum_neu' => '2026-08-06',
                'team_ids' => [$this->teamId],
                'pitch_id' => $this->pitchId,
                'beginn' => '19:00',
                'ende' => '20:30',
            ], $this->context());
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Kollidiert', $e->getConflicts()[0]);
        }

        self::assertCount(1, $this->dumpTable('training_slot'), 'nothing was written');
        self::assertCount(0, $this->dumpTable('slot_exception'), 'nothing was written');
    }

    public function testEditScopeEinzelnRejectsNonOccurrenceDate(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        try {
            // 2026-08-05 is a Wednesday, the slot runs on Tuesdays
            $booking->updateSlot($created['id'], [
                'edit_scope' => 'einzeln',
                'datum' => '2026-08-05',
                'team_ids' => [$this->teamId],
                'pitch_id' => $this->pitchId,
                'beginn' => '19:00',
                'ende' => '20:30',
            ], $this->context());
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('datum', $e->getErrors());
        }
    }

    public function testExceptionValidatesWeekdayAndRange(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        try {
            // 2026-08-05 is a Wednesday, slot runs on Tuesdays
            $booking->addException($created['id'], ['datum' => '2026-08-05'], $this->context());
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('datum', $e->getErrors());
        }
    }

    public function testValidationRejectsBrokenInput(): void
    {
        try {
            $this->bookingService()->createSlot(
                $this->slotInput([
                    'team_ids' => [],
                    'wochentage' => [9],
                    'beginn' => '25:00',
                    'gueltig_ab' => '2026-13-01',
                ]),
                $this->context(),
            );
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            self::assertArrayHasKey('team_ids', $errors);
            self::assertArrayHasKey('wochentage', $errors);
            self::assertArrayHasKey('beginn', $errors);
            self::assertArrayHasKey('gueltig_ab', $errors);
        }
    }
}
