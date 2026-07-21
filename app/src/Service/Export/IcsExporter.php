<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\SettingRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Service\Kalender\MatchDuration;
use App\Service\Kalender\SlotExpander;

/**
 * ICS feeds for calendar subscriptions (CLAUDE.md section 9): stable UIDs
 * from the aggregat_id so relocations MOVE the event in subscribed
 * calendars instead of duplicating it. Times are emitted in UTC (converted
 * from Europe/Berlin wall time), which is DST-correct in every client.
 */
final readonly class IcsExporter
{
    private const string UID_DOMAIN = 'vereinskalender';

    /** Horizon for expanding recurring slots into concrete VEVENTs. */
    private const string SLOT_RANGE_PAST = '-30 days';
    private const string SLOT_RANGE_FUTURE = '+180 days';

    /**
     * @param string|null $horizontVon override for tests (Y-m-d)
     * @param string|null $horizontBis override for tests (Y-m-d)
     */
    public function __construct(
        private TeamRepository $teams,
        private MatchRepository $matches,
        private TrainingSlotRepository $slots,
        private SlotExceptionRepository $exceptions,
        private PitchRepository $pitches,
        private SettingRepository $settings,
        private ?string $horizontVon = null,
        private ?string $horizontBis = null,
    ) {
    }

    /**
     * Matches of one team (or all teams when $teamId is null).
     */
    public function matchesFeed(?int $teamId): string
    {
        $teamNames = [];
        foreach ($this->teams->findAll() as $team) {
            $teamNames[(int) $team['id']] = (string) $team['kuerzel'];
        }

        $von = ($this->horizontVon ?? new \DateTimeImmutable(self::SLOT_RANGE_PAST)->format('Y-m-d')) . ' 00:00:00';
        $bis = ($this->horizontBis ?? new \DateTimeImmutable('+400 days')->format('Y-m-d')) . ' 23:59:59';

        $events = [];
        foreach ($this->matches->findInRange($von, $bis) as $match) {
            if ($teamId !== null && (int) $match['team_id'] !== $teamId) {
                continue;
            }
            $start = new \DateTimeImmutable((string) $match['anstoss']);
            $ende = $match['ende'] !== null ? (string) $match['ende'] : null;
            $kuerzel = $teamNames[(int) $match['team_id']] ?? '';

            $events[] = self::vevent(
                uid: sprintf('match-%d@%s', (int) $match['id'], self::UID_DOMAIN),
                start: $start,
                end: MatchDuration::effectiveEnd((string) $match['anstoss'], $ende),
                summary: trim($kuerzel . ': ' . (string) $match['gegner']),
                location: (string) $match['ort_text'],
                sequence: (int) $match['ics_sequence'],
                cancelled: (string) $match['status'] === 'abgesagt',
            );
        }

        $appName = $this->settings->get('app_name', 'Vereinskalender');
        $name = $teamId !== null
            ? $appName . ': Spielplan ' . ($teamNames[$teamId] ?? ('Team ' . $teamId))
            : $appName . ': Spielplan (alle Teams)';

        return self::calendar($name, $events);
    }

    /**
     * Occupancy of one pitch: expanded slot occurrences with stable
     * per-occurrence UIDs (slot id + date).
     */
    public function pitchFeed(int $pitchId): string
    {
        $pitch = $this->pitches->find($pitchId);
        $pitchName = $pitch !== null ? (string) $pitch['name'] : 'Platz ' . $pitchId;

        $teamNames = [];
        foreach ($this->teams->findAll() as $team) {
            $teamNames[(int) $team['id']] = (string) $team['kuerzel'];
        }

        $von = $this->horizontVon ?? new \DateTimeImmutable(self::SLOT_RANGE_PAST)->format('Y-m-d');
        $bis = $this->horizontBis ?? new \DateTimeImmutable(self::SLOT_RANGE_FUTURE)->format('Y-m-d');

        $slotRows = $this->slots->findOverlapping($von, $bis, $pitchId);
        $occurrences = SlotExpander::expand(
            $slotRows,
            $this->exceptions->findForSlots(array_map(static fn(array $s): int => (int) $s['id'], $slotRows)),
            $von,
            $bis,
        );

        $events = [];
        foreach ($occurrences as $occurrence) {
            $events[] = self::vevent(
                uid: sprintf('slot-%d-%s@%s', $occurrence->slotId, $occurrence->datum, self::UID_DOMAIN),
                start: $occurrence->start,
                end: $occurrence->end,
                summary: 'Training ' . implode('+', array_map(
                    static fn(int $teamId): string => $teamNames[$teamId] ?? ('Team ' . $teamId),
                    $occurrence->teamIds,
                )),
                location: $pitchName,
                sequence: 0,
                cancelled: false,
            );
        }

        $appName = $this->settings->get('app_name', 'Vereinskalender');

        return self::calendar($appName . ': Belegung ' . $pitchName, $events);
    }

    /**
     * @param list<string> $events
     */
    private static function calendar(string $name, array $events): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Vereinskalender//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::escape($name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
            'X-PUBLISHED-TTL:PT6H',
            ...$events,
            'END:VCALENDAR',
            '',
        ]);
    }

    private static function vevent(
        string $uid,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $summary,
        string $location,
        int $sequence,
        bool $cancelled,
    ): string {
        $utc = new \DateTimeZone('UTC');
        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTSTART:' . $start->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone($utc)->format('Ymd\THis\Z'),
            'SUMMARY:' . self::escape($summary),
        ];
        if ($location !== '') {
            $lines[] = 'LOCATION:' . self::escape($location);
        }
        $lines[] = 'SEQUENCE:' . $sequence;
        $lines[] = 'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'END:VEVENT';

        return implode("\r\n", $lines);
    }

    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\n", "\r"],
            ['\\\\', '\\;', '\\,', '\\n', ''],
            $value,
        );
    }
}
