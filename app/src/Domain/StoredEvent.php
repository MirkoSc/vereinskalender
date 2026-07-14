<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class StoredEvent
{
    /**
     * @param array<string, mixed> $payload full picture of the target state
     */
    public function __construct(
        public int $id,
        public AggregateType $aggregateType,
        public int $aggregateId,
        public EventType $eventType,
        public array $payload,
        public string $editorName,
        public string $ip,
        public EventSource $source,
        public string $erstelltAm,
        public ?string $excludedAt = null,
        public ?string $excludedVon = null,
        public ?string $excludedGrund = null,
        public ?int $korrekturVonEventId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            aggregateType: AggregateType::from((string) $row['aggregat_typ']),
            aggregateId: (int) $row['aggregat_id'],
            eventType: EventType::from((string) $row['event_typ']),
            payload: json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR),
            editorName: (string) $row['editor_name'],
            ip: (string) $row['ip'],
            source: EventSource::from((string) $row['quelle']),
            erstelltAm: (string) $row['erstellt_am'],
            excludedAt: $row['excluded_at'] !== null ? (string) $row['excluded_at'] : null,
            excludedVon: $row['excluded_von'] !== null ? (string) $row['excluded_von'] : null,
            excludedGrund: $row['excluded_grund'] !== null ? (string) $row['excluded_grund'] : null,
            korrekturVonEventId: $row['korrektur_von_event_id'] !== null
                ? (int) $row['korrektur_von_event_id']
                : null,
        );
    }
}
