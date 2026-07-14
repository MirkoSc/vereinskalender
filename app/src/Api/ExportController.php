<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
use App\Repository\UsageStatRepository;
use App\Service\Export\IcsExporter;

/**
 * Public ICS feeds under stable URLs (CLAUDE.md section 9). Feed calls are
 * counted in usage_stat (dimension = team/pitch).
 */
final readonly class ExportController
{
    public function __construct(
        private IcsExporter $exporter,
        private UsageStatRepository $stats,
    ) {
    }

    public function alleSpiele(Request $request): Response
    {
        $this->stats->increment('ics_feed', 'spiele');

        return self::ics($this->exporter->matchesFeed(null), 'spiele.ics');
    }

    /**
     * @param array<string, string> $params
     */
    public function teamSpiele(Request $request, array $params): Response
    {
        $teamId = (int) $params['id'];
        $this->stats->increment('ics_feed', 'team-' . $teamId);

        return self::ics($this->exporter->matchesFeed($teamId), 'team-' . $teamId . '.ics');
    }

    /**
     * @param array<string, string> $params
     */
    public function platzBelegung(Request $request, array $params): Response
    {
        $pitchId = (int) $params['id'];
        $this->stats->increment('ics_feed', 'platz-' . $pitchId);

        return self::ics($this->exporter->pitchFeed($pitchId), 'platz-' . $pitchId . '.ics');
    }

    private static function ics(string $content, string $filename): Response
    {
        return new Response(200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache',
        ], $content);
    }
}
