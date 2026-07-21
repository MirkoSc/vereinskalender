<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Domain\AggregateType;
use App\Domain\EventType;
use App\Domain\StoredEvent;
use App\Repository\NotificationQueueRepository;

/**
 * Write-path consumer of the event log (CLAUDE.md section 9): a created or
 * updated pitch_restriction (both arts) and a match update with a changed
 * kickoff or new status 'abgesagt' enqueue notifications. A restriction
 * DELETE deliberately does not notify (Issue #64, analog manual matches).
 * Called by the EventStore BEFORE the projection is applied so the old
 * match state is still readable for comparison.
 */
final readonly class NotificationTrigger
{
    public function __construct(
        private \PDO $pdo,
        private NotificationQueueRepository $queue,
    ) {
    }

    public function afterEventInsert(StoredEvent $event): void
    {
        if ($event->aggregateType === AggregateType::PitchRestriction
            && ($event->eventType === EventType::Created || $event->eventType === EventType::Updated)) {
            $this->queue->enqueue('platzsperrung', [
                'pitch_id' => (int) ($event->payload['pitch_id'] ?? 0),
                'art' => (string) ($event->payload['art'] ?? ''),
                'grund' => (string) ($event->payload['grund'] ?? ''),
                'von' => (string) ($event->payload['von'] ?? ''),
                'bis' => (string) ($event->payload['bis'] ?? ''),
            ], $event->id);

            return;
        }

        if ($event->aggregateType === AggregateType::Match
            && $event->eventType === EventType::Updated) {
            $stmt = $this->pdo->prepare('SELECT anstoss, status, team_id, gegner FROM `match` WHERE id = ?');
            $stmt->execute([$event->aggregateId]);
            $old = $stmt->fetch();
            if ($old === false) {
                return;
            }

            $neuerAnstoss = (string) ($event->payload['anstoss'] ?? '');
            $neuerStatus = (string) ($event->payload['status'] ?? '');
            $verlegt = $neuerAnstoss !== '' && $neuerAnstoss !== (string) $old['anstoss'];
            $abgesagt = $neuerStatus === 'abgesagt' && (string) $old['status'] !== 'abgesagt';

            if (!$verlegt && !$abgesagt) {
                return;
            }

            $this->queue->enqueue('spielaenderung', [
                'match_id' => $event->aggregateId,
                'team_id' => (int) ($event->payload['team_id'] ?? $old['team_id']),
                'gegner' => (string) ($event->payload['gegner'] ?? $old['gegner']),
                'alter_anstoss' => (string) $old['anstoss'],
                'neuer_anstoss' => $neuerAnstoss,
                'abgesagt' => $abgesagt,
            ], $event->id);
        }
    }
}
