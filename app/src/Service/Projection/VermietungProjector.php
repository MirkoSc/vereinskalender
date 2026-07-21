<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;
use App\Domain\VermietungArt;

final class VermietungProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Vermietung;
    }

    public function tableName(): string
    {
        return 'vermietung';
    }

    /**
     * raum_ids is a list value: every referenced room id is checked
     * individually during replay (Projector interface). Rooms/Sportheime are
     * only ever deactivated (delete-guarded), never deleted, so a referenced
     * id stays resolvable.
     */
    public function references(): array
    {
        return ['sportheim_id' => 'sportheim', 'raum_ids' => 'sportheim_raum'];
    }

    /**
     * Normalizes raum_ids to a plain int list (empty = whole house) and
     * upcasts events written before the art column existed (Issue #63) to
     * 'vermietung' - matching the DEFAULT of migration 017. Without the
     * upcast TableProjector::apply() would silently write NULL for a column
     * absent from the payload.
     */
    public function normalizePayload(array $payload): array
    {
        return [
            ...$payload,
            'art' => VermietungArt::fromPayload($payload['art'] ?? null)->value,
            'raum_ids' => array_values(array_map(intval(...), (array) ($payload['raum_ids'] ?? []))),
        ];
    }

    protected function columns(): array
    {
        return ['sportheim_id', 'art', 'raum_ids', 'von', 'bis', 'titel', 'kontakt', 'bemerkung'];
    }
}
