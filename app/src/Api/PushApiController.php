<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
use App\Repository\PushSubscriptionRepository;
use App\Repository\UsageStatRepository;
use App\Service\Push\PushSender;

/**
 * Push subscription endpoints (CLAUDE.md section 9): opt-in only via the
 * explicit bell button; preferences = categories + optional team filter.
 */
final readonly class PushApiController
{
    private const array KATEGORIEN = ['platzsperrung', 'spielaenderung'];

    public function __construct(
        private PushSubscriptionRepository $subscriptions,
        private PushSender $sender,
        private UsageStatRepository $stats,
    ) {
    }

    public function vapidKey(Request $request): Response
    {
        return Response::json(['public_key' => $this->sender->vapidPublicKey()]);
    }

    public function subscribe(Request $request): Response
    {
        $endpoint = trim((string) ($request->post['endpoint'] ?? ''));
        $keys = is_array($request->post['keys'] ?? null) ? $request->post['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? ''));

        if ($endpoint === '' || mb_strlen($endpoint) > 500 || $p256dh === '' || $auth === '') {
            return Response::json(['fehler' => ['abo' => 'Ungültige Push-Subscription.']], 422);
        }

        $kategorien = array_values(array_intersect(
            array_map(strval(...), (array) ($request->post['kategorien'] ?? [])),
            self::KATEGORIEN,
        ));
        if ($kategorien === []) {
            return Response::json(['fehler' => ['kategorien' => 'Bitte mindestens eine Kategorie wählen.']], 422);
        }

        $teamIds = array_values(array_filter(array_map(intval(...), (array) ($request->post['team_ids'] ?? []))));

        $this->subscriptions->upsert($endpoint, $p256dh, $auth, [
            'kategorien' => $kategorien,
            'team_ids' => $teamIds,
        ]);
        $this->stats->increment('push_abo');

        return Response::json(['ok' => true], 201);
    }

    public function unsubscribe(Request $request): Response
    {
        $endpoint = trim((string) ($request->post['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $this->subscriptions->delete($endpoint);
        }

        return Response::json(['ok' => true]);
    }
}
