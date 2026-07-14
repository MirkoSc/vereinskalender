<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;
use App\Domain\EventType;

/**
 * Applies events of one aggregate type to its projection table.
 * The payload is always a full picture of the target state, so applying
 * is a plain upsert (created/updated) or delete.
 */
interface Projector
{
    public function aggregateType(): AggregateType;

    public function tableName(): string;

    /**
     * Payload keys that reference other projection tables
     * (checked during replay: missing target => skip + report).
     * A list value in the payload means every element is a referenced id.
     *
     * @return array<string, string> payload key => referenced table name
     */
    public function references(): array;

    /**
     * Upgrades older payload shapes to the current one so historic events
     * stay replayable after a payload format change. Idempotent.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalizePayload(array $payload): array;

    /**
     * @param array<string, mixed> $payload
     * @param string $tableSuffix '' for live tables, '_rebuild' during rebuild
     */
    public function apply(EventType $eventType, int $aggregateId, array $payload, string $tableSuffix = ''): void;
}
