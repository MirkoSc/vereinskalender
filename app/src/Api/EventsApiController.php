<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
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
}
