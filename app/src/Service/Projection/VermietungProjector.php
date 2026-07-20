<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

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
     * Normalizes raum_ids to a plain int list (empty = whole house).
     */
    public function normalizePayload(array $payload): array
    {
        return [
            ...$payload,
            'raum_ids' => array_values(array_map(intval(...), (array) ($payload['raum_ids'] ?? []))),
        ];
    }

    protected function columns(): array
    {
        return ['sportheim_id', 'raum_ids', 'von', 'bis', 'titel', 'kontakt', 'bemerkung'];
    }
}
