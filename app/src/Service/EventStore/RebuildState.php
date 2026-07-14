<?php

declare(strict_types=1);

namespace App\Service\EventStore;

final readonly class RebuildState
{
    /**
     * @param list<SkippedEvent> $skipped the replay report
     */
    public function __construct(
        public string $startedAt,
        public int $lastEventId,
        public int $processed,
        public int $total,
        public bool $done,
        public array $skipped,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'started_at' => $this->startedAt,
            'last_event_id' => $this->lastEventId,
            'processed' => $this->processed,
            'total' => $this->total,
            'done' => $this->done,
            'skipped' => array_map(static fn(SkippedEvent $s): array => $s->toArray(), $this->skipped),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            startedAt: (string) $data['started_at'],
            lastEventId: (int) $data['last_event_id'],
            processed: (int) $data['processed'],
            total: (int) $data['total'],
            done: (bool) $data['done'],
            skipped: array_map(
                static fn(array $s): SkippedEvent => SkippedEvent::fromArray($s),
                $data['skipped'],
            ),
        );
    }
}
