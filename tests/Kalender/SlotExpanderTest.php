<?php

declare(strict_types=1);

namespace App\Tests\Kalender;

use App\Service\Kalender\SlotExpander;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ---- recurrence interval in weeks (intervall_wochen) ----

    /**
     * @return list<string>
     */
    private static function daten(array $occurrences): array
    {
        return array_map(static fn($o): string => $o->datum, $occurrences);
    }

    public function testBiweeklySkipsEveryOtherWeek(): void
    {
        $slot = self::slot(['intervall_wochen' => 2]);

        $occurrences = SlotExpander::expand([$slot], [], '2026-08-01', '2026-09-30');

        // gueltig_ab is a Saturday, so the series starts on the first Tuesday
        // after it and repeats fortnightly from THAT week on
        self::assertSame(
            ['2026-08-04', '2026-08-18', '2026-09-01', '2026-09-15', '2026-09-29'],
            self::daten($occurrences),
        );
    }

    #[DataProvider('intervalle')]
    public function testArbitraryWeekIntervals(int $intervall, array $erwartet): void
    {
        $slot = self::slot(['intervall_wochen' => $intervall]);

        $occurrences = SlotExpander::expand([$slot], [], '2026-08-01', '2026-09-30');

        self::assertSame($erwartet, self::daten($occurrences));
    }

    /**
     * @return array<string, array{int, list<string>}>
     */
    public static function intervalle(): array
    {
        return [
            'jede Woche' => [1, [
                '2026-08-04', '2026-08-11', '2026-08-18', '2026-08-25',
                '2026-09-01', '2026-09-08', '2026-09-15', '2026-09-22', '2026-09-29',
            ]],
            'alle 2 Wochen' => [2, ['2026-08-04', '2026-08-18', '2026-09-01', '2026-09-15', '2026-09-29']],
            'alle 3 Wochen' => [3, ['2026-08-04', '2026-08-25', '2026-09-15']],
            'alle 4 Wochen' => [4, ['2026-08-04', '2026-09-01', '2026-09-29']],
        ];
    }

    /**
     * The rhythm is anchored on the SLOT, never on the requested range -
     * otherwise the same series would show different dates depending on which
     * window happens to be fetched (grid vs. Terminliste vs. offline bundle).
     * Anchoring on max($rangeStart, gueltig_ab) - the pre-Rhythmus behaviour -
     * would answer 08.09./22.09. here instead of 15.09./29.09.
     */
    public function testIntervalAnchorIsIndependentOfRequestedRange(): void
    {
        $slot = self::slot(['intervall_wochen' => 2]);

        $ausschnitt = SlotExpander::expand([$slot], [], '2026-09-08', '2026-09-30');
        $voll = SlotExpander::expand([$slot], [], '2026-08-01', '2026-09-30');

        self::assertSame(['2026-09-15', '2026-09-29'], self::daten($ausschnitt));
        self::assertSame(
            array_values(array_filter(self::daten($voll), static fn(string $d): bool => $d >= '2026-09-08')),
            self::daten($ausschnitt),
        );
    }

    /**
     * All weekdays of a slot share the same occurrence weeks. Anchoring per
     * weekday instead would interleave them (Mo in one week, Mi in the next),
     * which is not what "alle 2 Wochen" means for a Mo+Mi training.
     */
    public function testIntervalKeepsAllWeekdaysInTheSameWeek(): void
    {
        // gueltig_ab on a Tuesday, so Mi comes first and Mo of that same week
        // is already past - the following weeks carry both days together
        $slot = self::slot([
            'wochentage' => '[1,3]',
            'intervall_wochen' => 2,
            'gueltig_ab' => '2026-08-04',
        ]);

        $occurrences = SlotExpander::expand([$slot], [], '2026-08-01', '2026-09-06');

        self::assertSame(
            ['2026-08-05', '2026-08-17', '2026-08-19', '2026-08-31', '2026-09-02'],
            self::daten($occurrences),
        );
    }

    /**
     * Mandatory DST coverage (CLAUDE.md section 12) for the interval path: a
     * fortnightly series can land exactly on a transition weekend or skip it
     * entirely - wall time stays 19:00 either way.
     */
    public function testBiweeklyFallDstTransitionKeepsWallTime(): void
    {
        // 2026-10-25 (the transition Sunday) is itself an occurrence here
        $slot = self::slot(['wochentage' => '[7]', 'intervall_wochen' => 2, 'gueltig_ab' => '2026-10-11']);

        $occurrences = SlotExpander::expand([$slot], [], '2026-10-04', '2026-11-15');

        self::assertSame(['2026-10-11', '2026-10-25', '2026-11-08'], self::daten($occurrences));
        foreach ($occurrences as $occurrence) {
            self::assertSame('19:00', $occurrence->start->format('H:i'));
        }
        self::assertSame('+02:00', $occurrences[0]->start->format('P'));
        self::assertSame('+01:00', $occurrences[1]->start->format('P'));
    }

    public function testBiweeklySpringDstTransitionKeepsWallTime(): void
    {
        $slot = self::slot(['wochentage' => '[7]', 'intervall_wochen' => 2, 'gueltig_ab' => '2027-03-14']);

        $occurrences = SlotExpander::expand([$slot], [], '2027-03-07', '2027-04-18');

        self::assertSame(['2027-03-14', '2027-03-28', '2027-04-11'], self::daten($occurrences));
        foreach ($occurrences as $occurrence) {
            self::assertSame('19:00', $occurrence->start->format('H:i'));
        }
        self::assertSame('+01:00', $occurrences[0]->start->format('P'));
        self::assertSame('+02:00', $occurrences[1]->start->format('P'));
    }

    /**
     * Events written before migration 018 carry no interval at all; they meant
     * "weekly" and must keep expanding exactly as before.
     */
    public function testMissingIntervalDefaultsToWeekly(): void
    {
        $ohneFeld = SlotExpander::expand([self::slot()], [], '2026-08-01', '2026-08-31');
        $mitEins = SlotExpander::expand([self::slot(['intervall_wochen' => 1])], [], '2026-08-01', '2026-08-31');

        self::assertSame(self::daten($mitEins), self::daten($ohneFeld));
        self::assertSame(['2026-08-04', '2026-08-11', '2026-08-18', '2026-08-25'], self::daten($ohneFeld));
    }

    public function testRangeFarBeyondValidityYieldsNothing(): void
    {
        $slot = self::slot([
            'intervall_wochen' => 2,
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2026-08-31',
        ]);

        self::assertSame([], SlotExpander::expand([$slot], [], '2030-01-01', '2030-12-31'));
    }

    public function testIntervalHonoursExceptions(): void
    {
        $slot = self::slot(['intervall_wochen' => 2]);
        $exceptions = [['slot_id' => 1, 'datum' => '2026-08-18', 'grund' => 'Ferien']];

        $occurrences = SlotExpander::expand([$slot], $exceptions, '2026-08-01', '2026-09-01');

        self::assertSame(['2026-08-04', '2026-09-01'], self::daten($occurrences));
    }
}
