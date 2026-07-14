<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Service\Import\IcsParseException;
use App\Service\Import\IcsParser;
use PHPUnit\Framework\TestCase;

final class IcsParserTest extends TestCase
{
    private static function wrap(string $events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//DE\r\n" . $events . "END:VCALENDAR\r\n";
    }

    public function testParsesBasicEvent(): void
    {
        $ics = self::wrap(
            "BEGIN:VEVENT\r\n" .
            "UID:spiel-1@fussball.de\r\n" .
            "DTSTART;TZID=Europe/Berlin:20260808T150000\r\n" .
            "SUMMARY:SV Musterstadt - FC Gegner\r\n" .
            "LOCATION:Sportanlage Musterstadt\r\n" .
            "SEQUENCE:2\r\n" .
            "END:VEVENT\r\n",
        );

        $events = IcsParser::parse($ics);

        self::assertCount(1, $events);
        self::assertSame('spiel-1@fussball.de', $events[0]->uid);
        self::assertSame('2026-08-08 15:00', $events[0]->start->format('Y-m-d H:i'));
        self::assertSame('SV Musterstadt - FC Gegner', $events[0]->summary);
        self::assertSame('Sportanlage Musterstadt', $events[0]->location);
        self::assertSame(2, $events[0]->sequence);
        self::assertFalse($events[0]->cancelled);
    }

    public function testConvertsUtcTimesToBerlin(): void
    {
        $ics = self::wrap(
            "BEGIN:VEVENT\r\nUID:u1\r\nDTSTART:20260808T130000Z\r\nSUMMARY:Test\r\nEND:VEVENT\r\n",
        );

        $events = IcsParser::parse($ics);

        // August = CEST = UTC+2
        self::assertSame('2026-08-08 15:00', $events[0]->start->format('Y-m-d H:i'));
        self::assertSame('Europe/Berlin', $events[0]->start->getTimezone()->getName());
    }

    public function testFloatingTimeIsAssumedBerlin(): void
    {
        $ics = self::wrap(
            "BEGIN:VEVENT\r\nUID:u1\r\nDTSTART:20261101T140000\r\nSUMMARY:Test\r\nEND:VEVENT\r\n",
        );

        self::assertSame('2026-11-01 14:00', IcsParser::parse($ics)[0]->start->format('Y-m-d H:i'));
    }

    public function testUnfoldsContinuationLines(): void
    {
        $ics = self::wrap(
            "BEGIN:VEVENT\r\n" .
            "UID:u1\r\n" .
            "DTSTART:20260808T150000\r\n" .
            "SUMMARY:SV Musterstadt -\r\n" .
            " FC Gegner mit sehr langem Namen\r\n" .
            "END:VEVENT\r\n",
        );

        self::assertSame(
            'SV Musterstadt -FC Gegner mit sehr langem Namen',
            IcsParser::parse($ics)[0]->summary,
        );
    }

    public function testUnescapesTextValues(): void
    {
        $ics = self::wrap(
            "BEGIN:VEVENT\r\n" .
            "UID:u1\r\n" .
            "DTSTART:20260808T150000\r\n" .
            "LOCATION:Sportweg 1\\, 12345 Musterstadt\\; Platz 2\r\n" .
            "SUMMARY:Test\r\n" .
            "END:VEVENT\r\n",
        );

        self::assertSame('Sportweg 1, 12345 Musterstadt; Platz 2', IcsParser::parse($ics)[0]->location);
    }

    public function testCancelledStatus(): void
    {
        $ics = self::wrap(
            "BEGIN:VEVENT\r\nUID:u1\r\nDTSTART:20260808T150000\r\nSTATUS:CANCELLED\r\nSUMMARY:Test\r\nEND:VEVENT\r\n",
        );

        self::assertTrue(IcsParser::parse($ics)[0]->cancelled);
    }

    public function testEventsWithoutUidOrDtstartAreDropped(): void
    {
        $ics = self::wrap(
            "BEGIN:VEVENT\r\nDTSTART:20260808T150000\r\nSUMMARY:ohne UID\r\nEND:VEVENT\r\n" .
            "BEGIN:VEVENT\r\nUID:u2\r\nSUMMARY:ohne DTSTART\r\nEND:VEVENT\r\n" .
            "BEGIN:VEVENT\r\nUID:u3\r\nDTSTART:20260808T150000\r\nSUMMARY:ok\r\nEND:VEVENT\r\n",
        );

        $events = IcsParser::parse($ics);

        self::assertCount(1, $events);
        self::assertSame('u3', $events[0]->uid);
    }

    public function testNonIcsContentIsRejected(): void
    {
        $this->expectException(IcsParseException::class);

        IcsParser::parse('<html><body>Fehlerseite</body></html>');
    }

    public function testEmptyCalendarIsValid(): void
    {
        self::assertSame([], IcsParser::parse(self::wrap('')));
    }
}
