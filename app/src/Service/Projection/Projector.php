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
     *
     * @return array<string, string> payload key => referenced table name
     */
    public function references(): array;

    /**
     * @param array<string, mixed> $payload
     * @param string $tableSuffix '' for live tables, '_rebuild' during rebuild
     */
    public function apply(EventType $eventType, int $aggregateId, array $payload, string $tableSuffix = ''): void;
}
