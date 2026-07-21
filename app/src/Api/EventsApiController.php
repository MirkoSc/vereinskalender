<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
use App\Repository\PitchRestrictionRepository;
use App\Repository\UsageStatRepository;
use App\Service\Kalender\AvailabilityService;
use App\Service\Kalender\EventFeedService;
use App\Service\Kalender\OfflineBundleService;
use App\Service\ValidationException;

final readonly class EventsApiController
{
    public function __construct(
        private EventFeedService $eventFeed,
        private AvailabilityService $availability,
        private OfflineBundleService $offlineBundle,
        private UsageStatRepository $stats,
        private PitchRestrictionRepository $restrictions,
    ) {
    }

    public function events(Request $request): Response
    {
        $this->stats->increment('api', 'events');

        try {
            return Response::json($this->eventFeed->feed($request->query));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 400);
        }
    }

    public function verfuegbarkeit(Request $request): Response
    {
        $this->stats->increment('api', 'verfuegbarkeit');

        $von = trim((string) ($request->query['von'] ?? ''));
        $bis = trim((string) ($request->query['bis'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $von) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $bis) !== 1) {
            return Response::json(['fehler' => ['von' => 'Bitte von/bis als Datum (JJJJ-MM-TT) angeben.']], 400);
        }

        try {
            return Response::json($this->availability->compute($von, $bis));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 400);
        }
    }

    public function offlineBundle(Request $request): Response
    {
        $this->stats->increment('offline_bundle');

        return Response::json($this->offlineBundle->build());
    }

    /**
     * Single restriction, full picture (unclipped von/bis, CLAUDE.md section
     * 5: reading is always public) - feeds the edit dialog from the
     * Verfügbarkeitsansicht (Issue #64), whose day-by-day timeline segments
     * only ever carry the clipped-to-that-day time window.
     *
     * @param array<string, string> $params
     */
    public function restriction(Request $request, array $params): Response
    {
        $restriction = $this->restrictions->find((int) $params['id']);
        if ($restriction === null) {
            return Response::json(['fehler' => ['id' => 'Einschränkung nicht gefunden.']], 404);
        }

        return Response::json([
            'id' => (int) $restriction['id'],
            'pitch_id' => (int) $restriction['pitch_id'],
            'von' => str_replace(' ', 'T', (string) $restriction['von']),
            'bis' => str_replace(' ', 'T', (string) $restriction['bis']),
            'art' => (string) $restriction['art'],
            'grund' => (string) $restriction['grund'],
        ]);
    }
}
