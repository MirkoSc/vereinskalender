<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Domain\EventType;
use App\Service\Kalender\ConflictException;
use App\Service\Kalender\SlotExpander;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

    public function testOverlappingSlotOnSamePitchWarnsButStillWrites(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        $otherTeam = $this->createTeam('E2');

        $result = $booking->createSlot(
            $this->slotInput(['team_ids' => [$otherTeam], 'beginn' => '20:00', 'ende' => '21:30']),
            $this->context(),
        );

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Doppelbelegung', $result['warnings'][0]);
        self::assertCount(2, $this->dumpTable('training_slot'), 'a double booking warns but both bookings are saved');
    }

    public function testMultiWeekdaySlotWarnsOnEveryWeekday(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(['wochentage' => [2, 4]]), $this->context());

        // Thursday only, same time: double-books the Thursday leg
        $result = $booking->createSlot(
            $this->slotInput(['team_ids' => [$this->createTeam('E2')], 'wochentage' => [4]]),
            $this->context(),
        );

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Doppelbelegung', $result['warnings'][0]);
    }

    public function testOverlappingSlotDetailsAreWarningsNotConflicts(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());

        $result = $booking->check(
            $this->slotInput(['team_ids' => [$this->createTeam('E2')], 'beginn' => '19:30', 'ende' => '21:00']),
        );

        self::assertSame([], $result->conflicts, 'a double booking must never be a hard conflict');
        self::assertNotSame([], $result->warnings);
        $slotDetails = array_values(array_filter($result->details, static fn($d) => $d->typ === 'slot'));
        self::assertNotSame([], $slotDetails);
        self::assertTrue($slotDetails[0]->istWarnung);
    }

    public function testGesperrtRestrictionStillBlocksEvenWithDoubleBooking(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(), $this->context());
        $this->restrictionService()->create([
            'pitch_id' => $this->pitchId,
            'von' => '2026-08-04 00:00',
            'bis' => '2026-08-05 00:00',
            'art' => 'gesperrt',
            'grund' => 'Platzpflege',
        ], $this->context());

        try {
            $booking->createSlot(
                $this->slotInput(['team_ids' => [$this->createTeam('E2')], 'beginn' => '19:30', 'ende' => '21:00']),
                $this->context(),
            );
            self::fail('Expected a conflict');
        } catch (ConflictException $e) {
            self::assertStringContainsString('gesperrt', $e->getConflicts()[0]);
        }

        self::assertCount(
            1,
            $this->dumpTable('training_slot'),
            'a gesperrt restriction still blocks, even though the double booking alone would only warn',
        );
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

    public function testMatchOnSamePitchWarnsButStillWrites(): void
    {
        // Saturday 2026-08-08 15:00 on this pitch, assumed 2h duration
        $this->createMatch($this->teamId, [
            'anstoss' => '2026-08-08 15:00:00',
            'heimspiel' => true,
            'pitch_id' => $this->pitchId,
        ]);

        $result = $this->bookingService()->createSlot(
            $this->slotInput(['wochentage' => [6], 'beginn' => '16:00', 'ende' => '17:30']),
            $this->context(),
        );

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Spiel gegen FC Gegner', $result['warnings'][0]);
        self::assertCount(1, $this->dumpTable('training_slot'), 'a double booking with a match warns but the slot is saved');
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
        $result = $this->bookingService()->createSlot(
            $this->slotInput(['wochentage' => [6], 'beginn' => '13:00', 'ende' => '14:30']),
            $this->context(),
        );
        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Spiel gegen Turnier', $result['warnings'][0]);

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

    public function testEditScopeEinzelnWarnsOnConflictWithOwnSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(['wochentage' => [2, 4]]), $this->context());

        // moving the Tuesday occurrence onto the (still busy) Thursday
        $result = $booking->updateSlot($created['id'], [
            'edit_scope' => 'einzeln',
            'datum' => '2026-08-04',
            'datum_neu' => '2026-08-06',
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'beginn' => '19:00',
            'ende' => '20:30',
        ], $this->context());

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Doppelbelegung', $result['warnings'][0]);
        self::assertCount(2, $this->dumpTable('training_slot'), 'the moved single occurrence is saved alongside the series');
        self::assertCount(1, $this->dumpTable('slot_exception'), 'the original Tuesday occurrence is excepted');
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

    // ---- delete scopes (same three as editing: alle / nachfolgende / einzeln) ----

    /**
     * @return list<string>
     */
    private function eventTypesFor(string $aggregateType, int $aggregateId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT event_typ FROM event WHERE aggregat_typ = ? AND aggregat_id = ? ORDER BY id',
        );
        $stmt->execute([$aggregateType, $aggregateId]);

        return array_column($stmt->fetchAll(), 'event_typ');
    }

    public function testDeleteSlotWithoutScopeRemovesWholeSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        // no edit_scope at all, as a stale cached client would send
        $booking->deleteSlot($created['id'], [], $this->context());

        self::assertCount(0, $this->dumpTable('training_slot'));
        self::assertCount(0, $this->dumpTable('slot_exception'));
        self::assertSame(['created', 'deleted'], $this->eventTypesFor('training_slot', $created['id']));
    }

    public function testDeleteScopeAlleRemovesWholeSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        // a stray 'datum' must be ignored for scope 'alle'
        $booking->deleteSlot($created['id'], ['edit_scope' => 'alle', 'datum' => '2026-09-01'], $this->context());

        self::assertCount(0, $this->dumpTable('training_slot'));
        self::assertCount(0, $this->dumpTable('slot_exception'));
        self::assertSame(['created', 'deleted'], $this->eventTypesFor('training_slot', $created['id']));
    }

    public function testDeleteScopeNachfolgendeTruncatesSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        $booking->deleteSlot($created['id'], ['edit_scope' => 'nachfolgende', 'datum' => '2026-09-01'], $this->context());

        $slots = $this->dumpTable('training_slot');
        self::assertCount(1, $slots, 'one event, no continuation slot');
        self::assertSame('2026-08-01', $slots[0]['gueltig_ab']);
        self::assertSame('2026-08-31', $slots[0]['gueltig_bis'], 'truncated to the day before the given date');
        self::assertSame('19:00:00', $slots[0]['beginn'], 'the remaining part keeps its own time');
        self::assertSame([2], json_decode((string) $slots[0]['wochentage'], true));
        self::assertCount(0, $this->dumpTable('slot_exception'));
        self::assertSame(['created', 'updated'], $this->eventTypesFor('training_slot', $created['id']));
    }

    /**
     * The guard must stay occurrence-based, not date-based: gueltig_ab
     * (2026-08-01) is a Saturday, the slot's first real occurrence is Tuesday
     * 2026-08-04. Deleting "diesen und alle folgenden" from that first
     * occurrence yields a day-before of 2026-08-03, which is still INSIDE
     * [gueltig_ab, gueltig_bis] - a naive date comparison would leave a
     * 2026-08-01..2026-08-03 stub with zero occurrences behind: invisible in
     * the calendar, and therefore never deletable again through the UI.
     */
    public function testDeleteScopeNachfolgendeAtFirstOccurrenceDeletesWholeSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        $booking->deleteSlot($created['id'], ['edit_scope' => 'nachfolgende', 'datum' => '2026-08-04'], $this->context());

        self::assertCount(0, $this->dumpTable('training_slot'), 'no occurrence-free stub left behind');
        self::assertSame(['created', 'deleted'], $this->eventTypesFor('training_slot', $created['id']));
    }

    public function testDeleteScopeNachfolgendeOnEintagesSlotDeletesIt(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->einzelterminInput(['datum_neu' => '2026-08-04']), $this->context());

        $booking->deleteSlot($created['id'], ['edit_scope' => 'nachfolgende', 'datum' => '2026-08-04'], $this->context());

        self::assertCount(0, $this->dumpTable('training_slot'));
    }

    public function testDeleteScopeNachfolgendeWithOnlyCancelledOccurrencesLeftDeletesSeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(['gueltig_bis' => '2026-08-31']), $this->context());
        $booking->addException($created['id'], ['datum' => '2026-08-04'], $this->context());

        // before the 08-11 cut, only the already-cancelled 08-04 occurrence
        // remains - the series is deleted, not truncated to an empty stub
        $booking->deleteSlot($created['id'], ['edit_scope' => 'nachfolgende', 'datum' => '2026-08-11'], $this->context());

        self::assertCount(0, $this->dumpTable('training_slot'));
        self::assertCount(1, $this->dumpTable('slot_exception'), 'the orphaned exception is deliberately not cleaned up');
    }

    public function testDeleteScopeEinzelnCancelsOneOccurrence(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        $booking->deleteSlot($created['id'], ['edit_scope' => 'einzeln', 'datum' => '2026-08-11'], $this->context());

        $slots = $this->dumpTable('training_slot');
        self::assertCount(1, $slots, 'the series itself is untouched');
        self::assertSame('2026-08-01', $slots[0]['gueltig_ab']);
        self::assertSame('2026-10-31', $slots[0]['gueltig_bis']);

        $exceptions = $this->dumpTable('slot_exception');
        self::assertCount(1, $exceptions);
        self::assertSame($created['id'], (int) $exceptions[0]['slot_id']);
        self::assertSame('2026-08-11', $exceptions[0]['datum']);
        self::assertSame('Termin gelöscht', $exceptions[0]['grund']);

        $occurrences = SlotExpander::expand([$slots[0]], $exceptions, '2026-08-01', '2026-08-31');
        $dates = array_map(static fn(object $o): string => $o->datum, $occurrences);
        self::assertNotContains('2026-08-11', $dates);
        self::assertContains('2026-08-04', $dates);
        self::assertContains('2026-08-18', $dates);
    }

    public function testDeleteScopeEinzelnRejectsNonOccurrenceDate(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        try {
            // 2026-08-05 is a Wednesday, the slot runs on Tuesdays
            $booking->deleteSlot($created['id'], ['edit_scope' => 'einzeln', 'datum' => '2026-08-05'], $this->context());
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('datum', $e->getErrors());
        }

        self::assertCount(1, $this->dumpTable('training_slot'));
        self::assertCount(0, $this->dumpTable('slot_exception'));
    }

    public function testDeleteScopeEinzelnRejectsAlreadyCancelledDate(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());
        $booking->addException($created['id'], ['datum' => '2026-08-11'], $this->context());

        try {
            $booking->deleteSlot($created['id'], ['edit_scope' => 'einzeln', 'datum' => '2026-08-11'], $this->context());
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('datum', $e->getErrors());
        }

        self::assertCount(1, $this->dumpTable('slot_exception'), 'no duplicate exception');
    }

    public function testDeleteScopeNachfolgendeRejectsDateOutsideValidity(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        try {
            $booking->deleteSlot($created['id'], ['edit_scope' => 'nachfolgende', 'datum' => '2026-11-10'], $this->context());
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('datum', $e->getErrors());
        }

        self::assertCount(1, $this->dumpTable('training_slot'));
    }

    public function testDeleteSlotRejectsUnknownScope(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(), $this->context());

        try {
            $booking->deleteSlot($created['id'], ['edit_scope' => 'quatsch', 'datum' => '2026-09-01'], $this->context());
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('edit_scope', $e->getErrors());
        }

        self::assertCount(1, $this->dumpTable('training_slot'));
    }

    /**
     * Determinism across all three delete scopes in one scenario: slot A is
     * cancelled once ('einzeln'), then truncated ('nachfolgende', orphaning
     * that exception), then fully removed ('alle'); slot B is truncated and
     * left alive so a surviving 'updated' row is compared too. A rebuild must
     * reproduce both tables exactly and must not report the orphaned
     * exception as skipped - its Created event precedes the slot's Deleted
     * event, so its slot_id reference still resolves at replay time.
     */
    public function testDeleteScopesReplayIdenticalToLiveProjection(): void
    {
        $booking = $this->bookingService();

        $slotA = $booking->createSlot($this->slotInput(), $this->context());
        $booking->deleteSlot($slotA['id'], ['edit_scope' => 'einzeln', 'datum' => '2026-08-11'], $this->context());
        $booking->deleteSlot($slotA['id'], ['edit_scope' => 'nachfolgende', 'datum' => '2026-09-01'], $this->context());
        $booking->deleteSlot($slotA['id'], ['edit_scope' => 'alle'], $this->context());

        $slotB = $booking->createSlot($this->slotInput(['wochentage' => [4]]), $this->context());
        $booking->deleteSlot($slotB['id'], ['edit_scope' => 'nachfolgende', 'datum' => '2026-09-01'], $this->context());

        $before = [
            'training_slot' => $this->dumpTable('training_slot'),
            'slot_exception' => $this->dumpTable('slot_exception'),
        ];

        $state = $this->runRebuildToCompletion($this->rebuildService());

        self::assertSame([], $state->skipped, 'the orphaned exception must not be reported as skipped');
        self::assertSame($before['training_slot'], $this->dumpTable('training_slot'));
        self::assertSame($before['slot_exception'], $this->dumpTable('slot_exception'));
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

    /**
     * Issue #63: every Sportheim-Termin art is non-blocking, so the
     * regressions below run against all of them. The expected hint wording
     * comes from VermietungArt::hinweis() - "Sportheim vermietet" would be
     * wrong for a cleaning slot.
     *
     * @return array<string, array{string, string}>
     */
    public static function sportheimTerminArten(): array
    {
        return [
            'vermietung' => ['vermietung', 'Sportheim vermietet'],
            'putzen' => ['putzen', 'Sportheim wird gereinigt'],
            'sitzung' => ['sitzung', 'Sitzung im Sportheim'],
        ];
    }

    /**
     * Issue #36: a Vermietung of the pitch's Sportheim overlapping the
     * booking must NEVER block or warn - createSlot saves without a
     * ConflictException and without confirmation-requiring warnings, the
     * overlap surfaces only in ConflictCheckResult::$hinweise.
     * Issue #63: that holds for EVERY art, not just rentals.
     */
    #[DataProvider('sportheimTerminArten')]
    public function testVermietungNeverBlocksOrWarnsBooking(string $art, string $erwarteterHinweis): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $pitchId = $this->createPitch($venueId, 'Rasenplatz Sportheim', '#0969da', 'RS', $sportheimId);
        $this->createVermietung($sportheimId, '2026-08-04 08:00:00', '2026-08-04 23:00:00', 'Geburtstagsfeier', [], $art);

        $result = $this->bookingService()->createSlot(
            $this->slotInput(['pitch_id' => $pitchId]),
            $this->context(),
        );

        self::assertSame([], $result['warnings']);
        self::assertCount(1, $this->dumpTable('training_slot'), 'booking is saved despite the overlapping Sportheim-Termin');

        // re-check the same booking, ignoring itself (as an edit dialog
        // would) - isolates the Vermietung hint from the booking's own slot
        $check = $this->bookingService()->check($this->slotInput(['pitch_id' => $pitchId]), $result['id']);
        self::assertFalse($check->hasConflicts());
        self::assertSame([], $check->warnings);
        self::assertCount(1, $check->hinweise);
        self::assertSame('vermietung', $check->hinweise[0]->typ, 'the hint category stays "vermietung" for every art');
        self::assertStringContainsString('Geburtstagsfeier', $check->hinweise[0]->nachricht);
        self::assertStringContainsString($erwarteterHinweis, $check->hinweise[0]->nachricht);
    }

    public function testPitchWithoutSportheimGetsNoVermietungHinweis(): void
    {
        // $this->pitchId (setUp) has no sportheim_id - a Vermietung anywhere
        // else must never leak into its hint list.
        $venueId = $this->createVenue('Anderer Verein');
        $sportheimId = $this->createSportheim($venueId);
        $this->createVermietung($sportheimId, '2026-08-04 08:00:00', '2026-08-04 23:00:00', 'Kegelabend');

        $check = $this->bookingService()->check($this->slotInput());

        self::assertSame([], $check->hinweise);
    }

    public function testVermietungHinweisAppliesEvenWithoutOverlappingRoom(): void
    {
        // an empty raum_ids list means "whole house" - the hint must appear
        // regardless of which/any room the booking would otherwise concern
        // (a pitch has no room concept at all).
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $raumId = $this->createSportheimRaum($sportheimId, 'Kegelbahn', 'KB');
        $pitchId = $this->createPitch($venueId, 'Rasenplatz Sportheim', '#0969da', 'RS', $sportheimId);
        $this->createVermietung($sportheimId, '2026-08-04 08:00:00', '2026-08-04 23:00:00', 'Kegelabend', [$raumId]);

        $check = $this->bookingService()->check($this->slotInput(['pitch_id' => $pitchId]));

        self::assertCount(1, $check->hinweise);
    }

    /**
     * Issue #63: the match path is non-blocking for every art too.
     */
    #[DataProvider('sportheimTerminArten')]
    public function testCheckMatchNeverWarnsForVermietungOverlap(string $art, string $erwarteterHinweis): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $pitchId = $this->createPitch($venueId, 'Rasenplatz Sportheim', '#0969da', 'RS', $sportheimId);
        $this->createVermietung($sportheimId, '2026-08-08 08:00:00', '2026-08-08 23:00:00', 'Vereinsfeier', [], $art);

        $result = $this->bookingService()->checkMatch(
            $pitchId,
            new \DateTimeImmutable('2026-08-08 15:00:00'),
            new \DateTimeImmutable('2026-08-08 17:00:00'),
        );

        self::assertFalse($result->hasConflicts());
        self::assertSame([], $result->warnings);
        self::assertCount(1, $result->hinweise);
        self::assertSame('vermietung', $result->hinweise[0]->typ);
        self::assertStringContainsString($erwarteterHinweis, $result->hinweise[0]->nachricht);
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

    /**
     * Issue #83: a single booking ("Einzeltermin") is - technically - a
     * training_slot with one weekday and gueltig_ab == gueltig_bis, the same
     * one-day shape the 'einzeln' edit scope already produces. The client
     * sends 'modus' => 'einzeltermin' plus a plain 'datum_neu' instead of
     * wochentage[]/gueltig_ab/gueltig_bis; BookingService::applyEinzeltermin
     * derives the full picture from that date.
     *
     * @return array<string, mixed>
     */
    private function einzelterminInput(array $overrides = []): array
    {
        return [
            'modus' => 'einzeltermin',
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'beginn' => '19:00',
            'ende' => '20:30',
            'datum_neu' => '2026-08-11', // Tuesday
            ...$overrides,
        ];
    }

    public function testCreateEinzeltermin(): void
    {
        $result = $this->bookingService()->createSlot($this->einzelterminInput(), $this->context());

        self::assertSame([], $result['warnings']);
        $slots = $this->dumpTable('training_slot');
        self::assertCount(1, $slots);
        self::assertSame([2], json_decode((string) $slots[0]['wochentage'], true), 'Tuesday derived from datum_neu');
        self::assertSame('2026-08-11', $slots[0]['gueltig_ab']);
        self::assertSame('2026-08-11', $slots[0]['gueltig_bis'], 'gueltig_ab == gueltig_bis: no series, one day');
    }

    public function testCreateEinzelterminExpandsToExactlyOneOccurrence(): void
    {
        $this->bookingService()->createSlot($this->einzelterminInput(), $this->context());

        $occurrences = SlotExpander::expand($this->dumpTable('training_slot'), [], '2026-08-01', '2026-08-31');

        self::assertCount(1, $occurrences);
        self::assertSame('2026-08-11', $occurrences[0]->datum);
        self::assertSame('2026-08-11 19:00:00', $occurrences[0]->start->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-11 20:30:00', $occurrences[0]->end->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function einzelterminWochentage(): array
    {
        return [
            'Sunday is ISO weekday 7' => ['2026-08-09', 7],
            'Monday is ISO weekday 1' => ['2026-08-10', 1],
            'Tuesday is ISO weekday 2' => ['2026-08-11', 2],
        ];
    }

    #[DataProvider('einzelterminWochentage')]
    public function testCreateEinzelterminDerivesWeekdayFromDate(string $datum, int $erwarteterWochentag): void
    {
        $result = $this->bookingService()->createSlot(
            $this->einzelterminInput(['datum_neu' => $datum]),
            $this->context(),
        );

        $slot = $this->dumpTable('training_slot')[0];
        self::assertSame($erwarteterWochentag, json_decode((string) $slot['wochentage'], true)[0]);
        self::assertSame($datum, $slot['gueltig_ab']);
        self::assertSame([], $result['warnings']);
    }

    public function testCreateEinzelterminRejectsMissingDatum(): void
    {
        try {
            $this->bookingService()->createSlot(
                $this->einzelterminInput(['datum_neu' => '']),
                $this->context(),
            );
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('datum_neu', $e->getErrors());
        }
        self::assertCount(0, $this->dumpTable('training_slot'));
    }

    public function testCreateEinzelterminWarnsOnConflictWithExistingSeries(): void
    {
        $booking = $this->bookingService();
        // Tuesdays 19:00-20:30, running through the Einzeltermin's date
        $booking->createSlot($this->slotInput(), $this->context());

        $result = $booking->createSlot(
            $this->einzelterminInput(['team_ids' => [$this->createTeam('E2')]]),
            $this->context(),
        );

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Doppelbelegung', $result['warnings'][0]);
        self::assertCount(2, $this->dumpTable('training_slot'), 'the Einzeltermin is saved alongside the series');
    }

    /**
     * Issue #83: editing an already one-day slot (kalender.js openEdit())
     * skips the three-way scope question and submits scope 'alle' directly -
     * there is no series to split off, so the slot is simply updated in
     * place: same id, no slot_exception, no second slot.
     */
    public function testUpdateEinzeltagesSlotInPlaceSkipsSplit(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->einzelterminInput(), $this->context());

        $result = $booking->updateSlot($created['id'], [
            'edit_scope' => 'alle',
            'modus' => 'einzeltermin',
            'datum_neu' => '2026-08-13', // moved to Thursday
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'beginn' => '18:00',
            'ende' => '19:30',
        ], $this->context());

        self::assertSame($created['id'], $result['id'], 'updated in place, no split into a second slot');
        self::assertCount(0, $this->dumpTable('slot_exception'), 'no exception - this is not an "einzeln" split');

        $slots = $this->dumpTable('training_slot');
        self::assertCount(1, $slots);
        self::assertSame([4], json_decode((string) $slots[0]['wochentage'], true), 'Thursday derived from the new date');
        self::assertSame('2026-08-13', $slots[0]['gueltig_ab']);
        self::assertSame('2026-08-13', $slots[0]['gueltig_bis']);
        self::assertSame('18:00:00', $slots[0]['beginn']);
    }

    // ---- Rhythmus: recurrence interval in weeks (intervall_wochen) ----

    /**
     * @return list<string>
     */
    private function slotDaten(string $von = '2026-08-01', string $bis = '2026-10-31'): array
    {
        return array_map(
            static fn($o): string => $o->datum,
            SlotExpander::expand($this->dumpTable('training_slot'), $this->dumpTable('slot_exception'), $von, $bis),
        );
    }

    public function testCreateSlotStoresInterval(): void
    {
        $this->bookingService()->createSlot($this->slotInput(['intervall_wochen' => 2]), $this->context());

        $slots = $this->dumpTable('training_slot');
        self::assertSame(2, (int) $slots[0]['intervall_wochen']);
        self::assertSame(
            ['2026-08-04', '2026-08-18', '2026-09-01', '2026-09-15', '2026-09-29', '2026-10-13', '2026-10-27'],
            $this->slotDaten(),
        );
    }

    public function testCreateSlotWithoutIntervalDefaultsToWeekly(): void
    {
        $this->bookingService()->createSlot($this->slotInput(), $this->context());

        self::assertSame(1, (int) $this->dumpTable('training_slot')[0]['intervall_wochen']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function ungueltigeIntervalle(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'above the maximum' => [5],
            'not a number' => ['abc'],
            'fractional' => ['2.5'],
        ];
    }

    #[DataProvider('ungueltigeIntervalle')]
    public function testCreateSlotRejectsInvalidInterval(mixed $intervall): void
    {
        try {
            $this->bookingService()->createSlot(
                $this->slotInput(['intervall_wochen' => $intervall]),
                $this->context(),
            );
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('intervall_wochen', $e->getErrors());
        }
        self::assertCount(0, $this->dumpTable('training_slot'));
    }

    /**
     * The whole point of anchoring the rhythm on the series' first occurrence:
     * splitting must not move a single date. The continuation starts at the
     * split occurrence, so together both parts reproduce exactly the dates the
     * undivided series had.
     */
    public function testEditScopeNachfolgendeKeepsTheRhythmOfABiweeklySeries(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(['intervall_wochen' => 2]), $this->context());
        $ungeteilt = $this->slotDaten();

        $booking->updateSlot($created['id'], $this->slotInput([
            'intervall_wochen' => 2,
            'edit_scope' => 'nachfolgende',
            'datum' => '2026-09-15', // an occurrence of the series
            'beginn' => '18:00',
            'ende' => '19:30',
        ]), $this->context());

        $slots = $this->dumpTable('training_slot');
        self::assertCount(2, $slots);
        self::assertSame('2026-09-14', $slots[0]['gueltig_bis']);
        self::assertSame('2026-09-15', $slots[1]['gueltig_ab']);
        self::assertSame(2, (int) $slots[1]['intervall_wochen'], 'the continuation keeps the rhythm');
        self::assertSame($ungeteilt, $this->slotDaten(), 'no occurrence moved');
    }

    public function testDeleteScopeNachfolgendeKeepsTheRhythmOfTheRemainder(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(['intervall_wochen' => 2]), $this->context());

        $booking->deleteSlot($created['id'], [
            'edit_scope' => 'nachfolgende',
            'datum' => '2026-09-15',
        ], $this->context());

        $slots = $this->dumpTable('training_slot');
        self::assertCount(1, $slots);
        self::assertSame('2026-09-14', $slots[0]['gueltig_bis']);
        self::assertSame(2, (int) $slots[0]['intervall_wochen']);
        self::assertSame(['2026-08-04', '2026-08-18', '2026-09-01'], $this->slotDaten());
    }

    public function testEditScopeEinzelnProducesAWeeklyOneDaySlot(): void
    {
        $booking = $this->bookingService();
        $created = $booking->createSlot($this->slotInput(['intervall_wochen' => 2]), $this->context());

        $booking->updateSlot($created['id'], [
            'edit_scope' => 'einzeln',
            'datum' => '2026-08-18',
            'datum_neu' => '2026-08-19',
            'intervall_wochen' => 2, // the (hidden) select still submits it
            'team_ids' => [$this->teamId],
            'pitch_id' => $this->pitchId,
            'beginn' => '18:00',
            'ende' => '19:00',
        ], $this->context());

        $slots = $this->dumpTable('training_slot');
        self::assertCount(2, $slots);
        self::assertSame(2, (int) $slots[0]['intervall_wochen'], 'the series keeps its rhythm');
        self::assertSame(1, (int) $slots[1]['intervall_wochen'], 'a one-day slot has no rhythm');
    }

    public function testEinzelterminIgnoresASubmittedInterval(): void
    {
        $this->bookingService()->createSlot(
            $this->einzelterminInput(['intervall_wochen' => 3]),
            $this->context(),
        );

        self::assertSame(1, (int) $this->dumpTable('training_slot')[0]['intervall_wochen']);
    }

    /**
     * Two fortnightly series on the same pitch and weekday, offset by one
     * week, never meet. Expanding them weekly (the pre-Rhythmus behaviour)
     * would report a Doppelbelegung for every single Tuesday.
     */
    public function testBiweeklySeriesInOppositeWeeksDoNotConflict(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(['intervall_wochen' => 2]), $this->context());

        $result = $booking->createSlot($this->slotInput([
            'intervall_wochen' => 2,
            'team_ids' => [$this->createTeam('E2')],
            'gueltig_ab' => '2026-08-11', // the off week
        ]), $this->context());

        self::assertSame([], $result['warnings']);
        self::assertCount(2, $this->dumpTable('training_slot'));
    }

    public function testBiweeklySeriesInTheSameWeekStillWarns(): void
    {
        $booking = $this->bookingService();
        $booking->createSlot($this->slotInput(['intervall_wochen' => 2]), $this->context());

        $result = $booking->createSlot($this->slotInput([
            'intervall_wochen' => 2,
            'team_ids' => [$this->createTeam('E2')],
        ]), $this->context());

        self::assertNotSame([], $result['warnings']);
        self::assertStringContainsString('Doppelbelegung', $result['warnings'][0]);
    }
}
