<?php

declare(strict_types=1);

namespace App\Tests\Kalender;

use App\Service\Kalender\VenueMatcher;
use PHPUnit\Framework\TestCase;

final class VenueMatcherTest extends TestCase
{
    private static function matcher(): VenueMatcher
    {
        // sorted by sortierung, as the repository delivers them
        return new VenueMatcher([
            ['begriff' => 'Musterstadt', 'venue_id' => 1],
            ['begriff' => 'Sportanlage Süd', 'venue_id' => 2],
            ['begriff' => 'Beispieldorf', 'venue_id' => 2],
        ]);
    }

    public function testMatchesKeywordInLocationText(): void
    {
        self::assertSame(1, self::matcher()->match('Rasenplatz, 12345 Musterstadt, Sportweg 1'));
    }

    public function testCaseInsensitiveMatching(): void
    {
        self::assertSame(1, self::matcher()->match('SPORTGELÄNDE MUSTERSTADT'));
        self::assertSame(2, self::matcher()->match('sportanlage süd, platz 2'));
    }

    public function testMultipleKeywordsPerVenue(): void
    {
        self::assertSame(2, self::matcher()->match('Am Hang 3, Beispieldorf'));
    }

    public function testFirstKeywordBySortierungWins(): void
    {
        // both keywords present: 'Musterstadt' comes first in sortierung
        self::assertSame(1, self::matcher()->match('Sportanlage Süd, Musterstadt'));
    }

    public function testNoMatchMeansAway(): void
    {
        self::assertNull(self::matcher()->match('Stadion Gegnerhausen'));
        self::assertNull(self::matcher()->match(''));
        self::assertNull(self::matcher()->match('   '));
    }
}
