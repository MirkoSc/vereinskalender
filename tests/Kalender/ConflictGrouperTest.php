<?php

declare(strict_types=1);

namespace App\Tests\Kalender;

use App\Service\Kalender\Conflict;
use App\Service\Kalender\ConflictGrouper;
use PHPUnit\Framework\TestCase;

/**
 * Pure grouping/aggregation logic (issue #9): occurrences of the same
 * verursacher (slot/match/restriction) collapse into one row with a count
 * and the earliest occurrence date. BookingService's conflict detection
 * itself is untouched; this only reshapes its structured output.
 */
final class ConflictGrouperTest extends TestCase
{
    public function testGroupsMultipleOccurrencesOfTheSameSlotIntoOneRow(): void
    {
        $details = [
            new Conflict('slot', 5, 'E2', '2026-07-28', '18:00', '19:30', false, 'msg1'),
            new Conflict('slot', 5, 'E2', '2026-07-21', '18:00', '19:30', false, 'msg2'),
            new Conflict('slot', 5, 'E2', '2026-08-04', '18:00', '19:30', false, 'msg3'),
        ];

        $groups = ConflictGrouper::group($details);

        self::assertCount(1, $groups);
        self::assertSame('slot', $groups[0]['typ']);
        self::assertSame(5, $groups[0]['verursacher_id']);
        self::assertSame(3, $groups[0]['anzahl']);
        self::assertSame('2026-07-21', $groups[0]['naechster_termin'], 'earliest occurrence, not insertion order');
        self::assertCount(3, $groups[0]['termine']);
        self::assertSame(
            ['2026-07-21', '2026-07-28', '2026-08-04'],
            array_map(static fn(array $t): string => $t['datum'], $groups[0]['termine']),
            'termine sorted by date',
        );
    }

    public function testDistinctVerursacherProduceSeparateGroups(): void
    {
        $details = [
            new Conflict('slot', 5, 'E2', '2026-07-21', '18:00', '19:30', false, 'msg'),
            new Conflict('slot', 6, 'E3', '2026-07-22', '18:00', '19:30', false, 'msg'),
            new Conflict('match', 9, 'FC Gegner', '2026-08-08', '15:00', '17:00', false, 'msg'),
        ];

        $groups = ConflictGrouper::group($details);

        self::assertCount(3, $groups);
    }

    public function testGroupsAreSortedByNextOccurrence(): void
    {
        $details = [
            new Conflict('slot', 1, 'A', '2026-09-01', '18:00', '19:30', false, 'msg'),
            new Conflict('slot', 2, 'B', '2026-07-01', '18:00', '19:30', false, 'msg'),
            new Conflict('match', 3, 'C', '2026-08-01', '15:00', '17:00', false, 'msg'),
        ];

        $groups = ConflictGrouper::group($details);

        self::assertSame(
            ['2026-07-01', '2026-08-01', '2026-09-01'],
            array_map(static fn(array $g): string => $g['naechster_termin'], $groups),
        );
    }

    public function testRestrictionWarningFlagIsPreservedPerGroup(): void
    {
        $details = [
            new Conflict('restriktion', 7, 'Rasen frisch gesät', '2026-08-04', '00:00', '23:59', true, 'msg'),
            new Conflict('restriktion', 7, 'Rasen frisch gesät', '2026-08-05', '00:00', '23:59', true, 'msg'),
        ];

        $groups = ConflictGrouper::group($details);

        self::assertCount(1, $groups);
        self::assertTrue($groups[0]['ist_warnung']);
        self::assertSame(2, $groups[0]['anzahl']);
    }

    public function testEmptyInputProducesNoGroups(): void
    {
        self::assertSame([], ConflictGrouper::group([]));
    }
}
