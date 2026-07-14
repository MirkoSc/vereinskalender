<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\Kalender\ConflictException;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Mandatory conflict check tests (CLAUDE.md section 12): 'gesperrt' blocks,
 * 'eingeschraenkt' allows with a warning, overlapping bookings collide.
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
            'team_id' => $this->teamId,
            'pitch_id' => $this->pitchId,
            'wochentag' => 2, // Dienstag
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
    }

    public function testOverlappingSlotOnSamePitchIsRejected(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        $otherTeam = $this->createTeam('E2');

        try {
            $booking->createSlot(
                $this->slotInput(['team_id' => $otherTeam, 'beginn' => '20:00', 'ende' => '21:30']),
                $this->context(),
            );
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Kollidiert', $e->getConflicts()[0]);
        }

        self::assertCount(1, $this->dumpTable('training_slot'), 'conflicting slot must not be saved');
    }

    public function testAdjacentSlotDoesNotConflict(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        // 20:30–22:00 directly after 19:00–20:30: no overlap
        $result = $booking->createSlot(
            $this->slotInput(['team_id' => $this->createTeam('E2'), 'beginn' => '20:30', 'ende' => '22:00']),
            $this->context(),
        );

        self::assertSame([], $result['warnings']);
    }

    public function testSamePitchOtherWeekdayDoesNotConflict(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        $result = $booking->createSlot($this->slotInput(['wochentag' => 4]), $this->context());

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
                $this->slotInput(['wochentag' => 6, 'beginn' => '16:00', 'ende' => '17:30']),
                $this->context(),
            );
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Spiel gegen FC Gegner', $e->getConflicts()[0]);
        }
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
                'team_id' => $this->createTeam('E2'),
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
                $this->slotInput(['wochentag' => 9, 'beginn' => '25:00', 'gueltig_ab' => '2026-13-01']),
                $this->context(),
            );
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            self::assertArrayHasKey('wochentag', $errors);
            self::assertArrayHasKey('beginn', $errors);
            self::assertArrayHasKey('gueltig_ab', $errors);
        }
    }
}
