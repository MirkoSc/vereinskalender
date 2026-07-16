<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class MatchProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Match;
    }

    public function tableName(): string
    {
        return 'match';
    }

    public function references(): array
    {
        return [
            'team_id' => 'team',
            'pitch_id' => 'pitch',
            'import_source_id' => 'import_source',
        ];
    }

    /**
     * Match events written before pitch_manuell existed carry no flag;
     * upcast them deterministically to false, matching the migration
     * DEFAULT (CLAUDE.md section 5).
     */
    public function normalizePayload(array $payload): array
    {
        return [
            ...$payload,
            'pitch_manuell' => (bool) ($payload['pitch_manuell'] ?? false),
        ];
    }

    protected function columns(): array
    {
        return [
            'team_id', 'anstoss', 'gegner', 'heimspiel', 'ort_text', 'pitch_id',
            'pitch_manuell', 'status', 'import_source_id', 'ics_uid', 'ics_sequence',
            'sync_hash',
        ];
    }
}
