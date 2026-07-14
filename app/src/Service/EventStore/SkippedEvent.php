<?php

declare(strict_types=1);

namespace App\Service\EventStore;

/**
 * One entry of the replay report (CLAUDE.md section 5): an event that could
 * not be applied because its aggregate or a referenced aggregate is missing.
 */
final readonly class SkippedEvent
{
    public function __construct(
        public int $eventId,
        public string $aggregatTyp,
        public int $aggregatId,
        public string $grund,
    ) {
    }

    /**
     * @return array{event_id: int, aggregat_typ: string, aggregat_id: int, grund: string}
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'aggregat_typ' => $this->aggregatTyp,
            'aggregat_id' => $this->aggregatId,
            'grund' => $this->grund,
        ];
    }

    /**
     * @param array{event_id: int, aggregat_typ: string, aggregat_id: int, grund: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['event_id'], $data['aggregat_typ'], $data['aggregat_id'], $data['grund']);
    }
}
