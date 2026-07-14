<?php

declare(strict_types=1);

namespace App\Tests\Kalender;

use App\Service\Kalender\SlotExpander;
use PHPUnit\Framework\TestCase;

final class SlotExpanderTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function slot(array $overrides = []): array
    {
        return [
            'id' => 1,
            // JSON strings like the projection rows deliver them
            'team_ids' => '[10]',
            'pitch_id' => 20,
            'wochentage' => '[2]', // Dienstag
            'beginn' => '19:00:00',
            'ende' => '20:30:00',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2027-06-30',
            ...$overrides,
        ];
    }

    public function testExpandsWeeklyOccurrencesWithinRange(): void
    {
        $occurrences = SlotExpander::expand([self::slot()], [], '2026-08-01', '2026-08-31');

        self::assertSame(
            ['2026-08-04', '2026-08-11', '2026-08-18', '2026-08-25'],
            array_map(static fn($o): string => $o->datum, $occurrences),
        );
        self::assertSame('2026-08-04 19:00', $occurrences[0]->start->format('Y-m-d H:i'));
        self::assertSame('2026-08-04 20:30', $occurrences[0]->end->format('Y-m-d H:i'));
        self::assertSame([10], $occurrences[0]->teamIds);
    }

    public function testExpandsMultipleWeekdays(): void
    {
        // Dienstag + Donnerstag at the same time is ONE slot
        $slot = self::slot(['wochentage' => '[2,4]']);

        $occurrences = SlotExpander::expand([$slot], [], '2026-08-01', '2026-08-14');

        self::assertSame(
            ['2026-08-04', '2026-08-06', '2026-08-11', '2026-08-13'],
            array_map(static fn($o): string => $o->datum, $occurrences),
        );
    }

    public function testAcceptsPhpListsFromValidatedPayloads(): void
    {
        // conflict checks expand candidate payloads before they are stored
        $slot = self::slot(['team_ids' => [10, 11], 'wochentage' => [2, 4]]);

        $occurrences = SlotExpander::expand([$slot], [], '2026-08-01', '2026-08-07');

        self::assertSame(
            ['2026-08-04', '2026-08-06'],
            array_map(static fn($o): string => $o->datum, $occurrences),
        );
        self::assertSame([10, 11], $occurrences[0]->teamIds);
    }

    public function testRespectsValidityRange(): void
    {
        $slot = self::slot(['gueltig_ab' => '2026-08-12', 'gueltig_bis' => '2026-08-19']);

        $occurrences = SlotExpander::expand([$slot], [], '2026-08-01', '2026-08-31');

        self::assertSame(['2026-08-18'], array_map(static fn($o): string => $o->datum, $occurrences));
    }

    public function testSkipsExceptions(): void
    {
        $exceptions = [['slot_id' => 1, 'datum' => '2026-08-11', 'grund' => 'Ferien']];

        $occurrences = SlotExpander::expand([self::slot()], $exceptions, '2026-08-01', '2026-08-31');

        self::assertSame(
            ['2026-08-04', '2026-08-18', '2026-08-25'],
            array_map(static fn($o): string => $o->datum, $occurrences),
        );
    }

    public function testExceptionOfOtherSlotDoesNotApply(): void
    {
        $exceptions = [['slot_id' => 99, 'datum' => '2026-08-11', 'grund' => '']];

        $occurrences = SlotExpander::expand([self::slot()], $exceptions, '2026-08-01', '2026-08-31');

        self::assertCount(4, $occurrences);
    }

    /**
     * Mandatory DST tests (CLAUDE.md section 12): times are local wall time
     * across both transition weekends. Spring 2027: March 28. A Sunday slot
     * at 19:00 stays 19:00 local before, on, and after the transition.
     */
    public function testSpringDstTransitionKeepsWallTime(): void
    {
        $slot = self::slot(['wochentage' => '[7]']); // Sonntag

        $occurrences = SlotExpander::expand([$slot], [], '2027-03-21', '2027-04-04');

        self::assertSame(
            ['2027-03-21', '2027-03-28', '2027-04-04'],
            array_map(static fn($o): string => $o->datum, $occurrences),
        );
        foreach ($occurrences as $occurrence) {
            self::assertSame('19:00', $occurrence->start->format('H:i'));
            self::assertSame('Europe/Berlin', $occurrence->start->getTimezone()->getName());
        }
        // UTC offset changes across the transition: CET +01:00 -> CEST +02:00
        self::assertSame('+01:00', $occurrences[0]->start->format('P'));
        self::assertSame('+02:00', $occurrences[1]->start->format('P'));
    }

    /**
     * Fall 2026: October 25. Wall time stays 19:00, offset flips back.
     */
    public function testFallDstTransitionKeepsWallTime(): void
    {
        $slot = self::slot(['wochentage' => '[7]']); // Sonntag

        $occurrences = SlotExpander::expand([$slot], [], '2026-10-18', '2026-11-01');

        self::assertSame(
            ['2026-10-18', '2026-10-25', '2026-11-01'],
            array_map(static fn($o): string => $o->datum, $occurrences),
        );
        foreach ($occurrences as $occurrence) {
            self::assertSame('19:00', $occurrence->start->format('H:i'));
        }
        self::assertSame('+02:00', $occurrences[0]->start->format('P'));
        self::assertSame('+01:00', $occurrences[1]->start->format('P'));
    }

    public function testEmptyRangeYieldsNothing(): void
    {
        $occurrences = SlotExpander::expand([self::slot()], [], '2026-08-05', '2026-08-10');

        self::assertSame([], $occurrences);
    }
}
