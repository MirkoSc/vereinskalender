<?php

declare(strict_types=1);

namespace App\Tests\Kalender;

use App\Service\Kalender\NextEventDate;
use PHPUnit\Framework\TestCase;

/**
 * Issue #52: the Terminliste stops when nothing follows the loaded range.
 * Training slots are recurrence rules, so "does anything follow?" needs the
 * rule, not a row - these tests pin the LOWER BOUND contract of
 * NextEventDate (never later than the true next occurrence, null only when
 * the rule is truly spent). Mirrored in tests/js/offline-events.test.js.
 */
final class NextEventDateTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function slot(array $overrides = []): array
    {
        return [
            'id' => 1,
            // JSON string like the projection rows deliver it
            'wochentage' => '[1]',
            'gueltig_ab' => '2026-08-01',
            'gueltig_bis' => '2027-06-30',
            ...$overrides,
        ];
    }

    public function testNoSlotsMeansNothingFollows(): void
    {
        self::assertNull(NextEventDate::ausSlots([], '2026-12-02'));
    }

    public function testFindsFirstOccurrenceAfterALongGap(): void
    {
        // slot only starts in the second half of the season: the first
        // Monday on or after 2027-03-01 is 2027-03-01 itself
        $slot = self::slot(['gueltig_ab' => '2027-03-01']);

        self::assertSame('2027-03-01', NextEventDate::ausSlots([$slot], '2026-12-02'));
    }

    public function testAdvancesToTheNextMatchingWeekday(): void
    {
        // 2026-12-03 is a Thursday; the next Monday is 2026-12-07
        self::assertSame('2026-12-07', NextEventDate::ausSlots([self::slot()], '2026-12-02'));
    }

    public function testExpiredSlotContributesNothing(): void
    {
        $slot = self::slot(['gueltig_bis' => '2026-11-30']);

        self::assertNull(NextEventDate::ausSlots([$slot], '2026-12-02'));
    }

    public function testSlotEndingBeforeItsNextWeekdayContributesNothing(): void
    {
        // still valid on 2026-12-03/04 but expires before the next Monday
        $slot = self::slot(['gueltig_bis' => '2026-12-04']);

        self::assertNull(NextEventDate::ausSlots([$slot], '2026-12-02'));
    }

    public function testTakesTheEarliestAcrossSlotsAndWeekdays(): void
    {
        $slots = [
            self::slot(['id' => 1, 'wochentage' => '[1]']),          // Monday 2026-12-07
            self::slot(['id' => 2, 'wochentage' => [4, 6]]),          // Thursday 2026-12-03
        ];

        self::assertSame('2026-12-03', NextEventDate::ausSlots($slots, '2026-12-02'));
    }

    public function testOccurrenceOnTheDayAfterBisCounts(): void
    {
        // strictly after `bis`, so the very next day is fair game
        $slot = self::slot(['wochentage' => '[4]']); // 2026-12-03 is a Thursday

        self::assertSame('2026-12-03', NextEventDate::ausSlots([$slot], '2026-12-02'));
    }

    /**
     * The bound ignores slot exceptions on purpose: an exception can only
     * remove an occurrence, so the answer may come out too EARLY (one extra
     * empty batch for the client) but never too late (which would drop
     * events off the end of the list).
     */
    public function testIgnoresExceptionsAndStaysALowerBound(): void
    {
        self::assertSame('2026-12-07', NextEventDate::ausSlots([self::slot()], '2026-12-02'));
    }

    public function testFruehesteIgnoresNullCandidates(): void
    {
        self::assertSame('2027-01-10', NextEventDate::frueheste([null, '2027-03-07', null, '2027-01-10']));
    }

    public function testFruehesteIsNullWhenEveryCandidateIsNull(): void
    {
        self::assertNull(NextEventDate::frueheste([null, null]));
    }

    // ---- Issue #81: ausSlotsVor()/spaeteste() - Spiegelbild für die
    // Vergangenheits-Nachladung der Terminliste ("Vergangenheit anzeigen"). ----

    public function testNoSlotsMeansNothingPrecedes(): void
    {
        self::assertNull(NextEventDate::ausSlotsVor([], '2026-12-02'));
    }

    public function testFindsLastOccurrenceBeforeALongGap(): void
    {
        // slot only ran in the first half of the season: the last Monday on
        // or before 2026-11-30 is 2026-11-30 itself
        $slot = self::slot(['gueltig_bis' => '2026-11-30']);

        self::assertSame('2026-11-30', NextEventDate::ausSlotsVor([$slot], '2027-03-01'));
    }

    public function testGoesBackToThePreviousMatchingWeekday(): void
    {
        // 2026-12-03 is a Thursday; the previous Monday is 2026-11-30
        self::assertSame('2026-11-30', NextEventDate::ausSlotsVor([self::slot()], '2026-12-04'));
    }

    public function testNotYetStartedSlotContributesNothing(): void
    {
        $slot = self::slot(['gueltig_ab' => '2027-03-01']);

        self::assertNull(NextEventDate::ausSlotsVor([$slot], '2026-12-02'));
    }

    public function testSlotStartingAfterItsPreviousWeekdayContributesNothing(): void
    {
        // still valid on 2026-12-01/02 but only starts after the previous Monday
        $slot = self::slot(['gueltig_ab' => '2026-12-02']);

        self::assertNull(NextEventDate::ausSlotsVor([$slot], '2026-12-04'));
    }

    public function testTakesTheLatestAcrossSlotsAndWeekdays(): void
    {
        $slots = [
            self::slot(['id' => 1, 'wochentage' => '[1]']),          // Monday 2026-11-30
            self::slot(['id' => 2, 'wochentage' => [4, 6]]),          // Saturday 2026-12-05
        ];

        self::assertSame('2026-12-05', NextEventDate::ausSlotsVor($slots, '2026-12-07'));
    }

    public function testOccurrenceOnTheDayBeforeVorCounts(): void
    {
        // strictly before `vor`, so the very previous day is fair game
        $slot = self::slot(['wochentage' => '[3]']); // 2026-12-02 is a Wednesday

        self::assertSame('2026-12-02', NextEventDate::ausSlotsVor([$slot], '2026-12-03'));
    }

    /**
     * Same asymmetry as ausSlots(): ignoring exceptions may make the answer
     * too recent (one extra empty batch loading backwards) but never too
     * far back, which would drop existing history off the top of the list.
     */
    public function testIgnoresExceptionsAndStaysAnUpperBound(): void
    {
        self::assertSame('2026-11-30', NextEventDate::ausSlotsVor([self::slot()], '2026-12-04'));
    }

    public function testSpaetesteIgnoresNullCandidates(): void
    {
        self::assertSame('2027-03-07', NextEventDate::spaeteste([null, '2027-01-10', null, '2027-03-07']));
    }

    public function testSpaetesteIsNullWhenEveryCandidateIsNull(): void
    {
        self::assertNull(NextEventDate::spaeteste([null, null]));
    }
}
