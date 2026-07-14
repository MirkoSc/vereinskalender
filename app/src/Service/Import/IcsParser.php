<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Minimal iCalendar parser for match feeds (e.g. fussball.de). Deliberately
 * hand-written and dependency-free (CLAUDE.md section 12: third-party libs
 * need a PHP 8.5 compatibility check first; this subset is small).
 *
 * Supports: line unfolding, VEVENT blocks, UID, SUMMARY, LOCATION,
 * SEQUENCE, STATUS, DTSTART as UTC ("...Z"), with TZID parameter, floating
 * (assumed Europe/Berlin), or VALUE=DATE. Times are converted to
 * Europe/Berlin (CLAUDE.md section 12).
 */
final class IcsParser
{
    /**
     * @return list<IcsEvent> events without UID or DTSTART are dropped
     */
    public static function parse(string $ics): array
    {
        if (!str_contains($ics, 'BEGIN:VCALENDAR')) {
            throw new IcsParseException('Response is not an iCalendar file (missing BEGIN:VCALENDAR)');
        }

        $events = [];
        foreach (self::eventBlocks(self::unfold($ics)) as $properties) {
            $uid = trim($properties['UID']['value'] ?? '');
            $dtstart = $properties['DTSTART'] ?? null;
            if ($uid === '' || $dtstart === null) {
                continue;
            }

            $start = self::parseDateTime($dtstart['value'], $dtstart['params']);
            if ($start === null) {
                continue;
            }

            $events[] = new IcsEvent(
                uid: $uid,
                start: $start,
                summary: self::unescape($properties['SUMMARY']['value'] ?? ''),
                location: self::unescape($properties['LOCATION']['value'] ?? ''),
                sequence: (int) ($properties['SEQUENCE']['value'] ?? 0),
                cancelled: strtoupper(trim($properties['STATUS']['value'] ?? '')) === 'CANCELLED',
            );
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private static function unfold(string $ics): array
    {
        $rawLines = preg_split('/\r\n|\n|\r/', $ics) ?: [];

        $lines = [];
        foreach ($rawLines as $line) {
            if (($line[0] ?? '') === ' ' || ($line[0] ?? '') === "\t") {
                if ($lines !== []) {
                    $lines[count($lines) - 1] .= substr($line, 1);
                }
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return list<array<string, array{value: string, params: array<string, string>}>>
     */
    private static function eventBlocks(array $lines): array
    {
        $blocks = [];
        $current = null;

        foreach ($lines as $line) {
            if (strtoupper(trim($line)) === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }
            if (strtoupper(trim($line)) === 'END:VEVENT') {
                if ($current !== null) {
                    $blocks[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue;
            }

            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }

            $nameWithParams = substr($line, 0, $colon);
            $value = substr($line, $colon + 1);

            $parts = explode(';', $nameWithParams);
            $name = strtoupper(array_shift($parts));

            $params = [];
            foreach ($parts as $param) {
                $eq = strpos($param, '=');
                if ($eq !== false) {
                    $params[strtoupper(substr($param, 0, $eq))] = trim(substr($param, $eq + 1), '"');
                }
            }

            $current[$name] = ['value' => $value, 'params' => $params];
        }

        return $blocks;
    }

    /**
     * @param array<string, string> $params
     */
    private static function parseDateTime(string $value, array $params): ?\DateTimeImmutable
    {
        $value = trim($value);
        $berlin = new \DateTimeZone('Europe/Berlin');

        try {
            // VALUE=DATE or bare date: all-day, midnight local time
            if (preg_match('/^\d{8}$/', $value) === 1) {
                return new \DateTimeImmutable($value, $berlin);
            }

            // UTC: 20260808T130000Z
            if (str_ends_with($value, 'Z')) {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'))->setTimezone($berlin);
            }

            // with TZID param, otherwise floating time = Europe/Berlin
            $timezone = $berlin;
            if (isset($params['TZID'])) {
                try {
                    $timezone = new \DateTimeZone($params['TZID']);
                } catch (\Exception) {
                    $timezone = $berlin;
                }
            }

            return new \DateTimeImmutable($value, $timezone)->setTimezone($berlin);
        } catch (\Exception) {
            return null;
        }
    }

    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback(
            '/\\\\(.)/s',
            static fn(array $m): string => match ($m[1]) {
                'n', 'N' => "\n",
                default => $m[1],
            },
            trim($value),
        );
    }
}
