<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Repository\NotificationQueueRepository;
use App\Repository\PitchRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\TeamRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/**
 * Delivers queued notifications during the cron run (CLAUDE.md section 9).
 * The VAPID key pair is generated once and lives in shared/vapid.json;
 * endpoints answering 404/410 are removed.
 */
final class PushSender
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly NotificationQueueRepository $queue,
        private readonly TeamRepository $teams,
        private readonly PitchRepository $pitches,
        private readonly string $vapidFile,
    ) {
    }

    public function vapidPublicKey(): string
    {
        return $this->vapidKeys()['publicKey'];
    }

    /**
     * @return array{verarbeitet: int, gesendet: int, entfernt: int}
     */
    public function processQueue(int $limit = 50): array
    {
        $entries = $this->queue->pending($limit);
        if ($entries === []) {
            return ['verarbeitet' => 0, 'gesendet' => 0, 'entfernt' => 0];
        }

        $subscriptions = $this->subscriptions->findAll();
        $gesendet = 0;
        $entfernt = 0;

        $webPush = null;
        if ($subscriptions !== []) {
            $keys = $this->vapidKeys();
            $webPush = new WebPush(['VAPID' => [
                'subject' => 'https://github.com/' . \App\Service\Update\ReleaseDownloader::REPO,
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
            ]]);
        }

        foreach ($entries as $entry) {
            $payload = json_decode((string) $entry['payload'], true) ?: [];
            $notification = $this->buildNotification((string) $entry['typ'], $payload);

            if ($webPush !== null && $notification !== null) {
                foreach ($subscriptions as $subscription) {
                    if (!self::matches($subscription, (string) $entry['typ'], $payload)) {
                        continue;
                    }
                    $webPush->queueNotification(
                        Subscription::create([
                            'endpoint' => (string) $subscription['endpoint'],
                            'publicKey' => (string) $subscription['p256dh'],
                            'authToken' => (string) $subscription['auth'],
                            'contentEncoding' => 'aes128gcm',
                        ]),
                        json_encode($notification, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    );
                    $gesendet++;
                }
            }

            $this->queue->markSent((int) $entry['id']);
        }

        if ($webPush !== null) {
            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    $this->subscriptions->delete((string) $report->getRequest()->getUri());
                    $entfernt++;
                }
            }
        }

        return ['verarbeitet' => count($entries), 'gesendet' => $gesendet, 'entfernt' => $entfernt];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{titel: string, text: string, url: string}|null
     */
    public function buildNotification(string $typ, array $payload): ?array
    {
        if ($typ === 'platzsperrung') {
            $pitch = $this->pitches->find((int) ($payload['pitch_id'] ?? 0));
            $pitchName = $pitch !== null ? (string) $pitch['name'] : 'Platz';
            $von = self::germanDateTime((string) ($payload['von'] ?? ''));
            $bis = self::germanDateTime((string) ($payload['bis'] ?? ''));

            return [
                'titel' => ($payload['art'] ?? '') === 'gesperrt'
                    ? $pitchName . ' gesperrt'
                    : $pitchName . ' eingeschränkt',
                'text' => sprintf('%s – %s: %s', $von, $bis, (string) ($payload['grund'] ?? '')),
                'url' => '/verfuegbarkeit',
            ];
        }

        if ($typ === 'spielaenderung') {
            $team = $this->teams->find((int) ($payload['team_id'] ?? 0));
            $teamName = $team !== null ? (string) $team['name'] : 'Team';
            $gegner = (string) ($payload['gegner'] ?? '');

            if (($payload['abgesagt'] ?? false) === true) {
                return [
                    'titel' => 'Spiel abgesagt: ' . $teamName,
                    'text' => $gegner . ' (' . self::germanDateTime((string) ($payload['alter_anstoss'] ?? '')) . ') fällt aus.',
                    'url' => '/spielplan',
                ];
            }

            return [
                'titel' => 'Spiel verlegt: ' . $teamName,
                'text' => sprintf(
                    '%s: neu am %s (vorher %s).',
                    $gegner,
                    self::germanDateTime((string) ($payload['neuer_anstoss'] ?? '')),
                    self::germanDateTime((string) ($payload['alter_anstoss'] ?? '')),
                ),
                'url' => '/spielplan',
            ];
        }

        return null;
    }

    /**
     * Preference matching: category must be subscribed; an optional team
     * filter only applies to notifications that carry a team.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $payload
     */
    public static function matches(array $subscription, string $typ, array $payload): bool
    {
        $praeferenzen = json_decode((string) $subscription['praeferenzen'], true) ?: [];
        $kategorien = array_map(strval(...), (array) ($praeferenzen['kategorien'] ?? []));
        if (!in_array($typ, $kategorien, true)) {
            return false;
        }

        $teamIds = array_map(intval(...), (array) ($praeferenzen['team_ids'] ?? []));
        $teamId = (int) ($payload['team_id'] ?? 0);
        if ($teamIds !== [] && $teamId > 0 && !in_array($teamId, $teamIds, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    private function vapidKeys(): array
    {
        if (is_file($this->vapidFile)) {
            $keys = json_decode((string) file_get_contents($this->vapidFile), true);
            if (is_array($keys) && isset($keys['publicKey'], $keys['privateKey'])) {
                return ['publicKey' => (string) $keys['publicKey'], 'privateKey' => (string) $keys['privateKey']];
            }
        }

        $keys = VAPID::createVapidKeys();
        $dir = dirname($this->vapidFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($this->vapidFile, json_encode($keys, JSON_THROW_ON_ERROR), LOCK_EX);

        return $keys;
    }

    private static function germanDateTime(string $datetime): string
    {
        try {
            return new \DateTimeImmutable($datetime)->format('d.m.Y H:i');
        } catch (\Exception) {
            return $datetime;
        }
    }
}
