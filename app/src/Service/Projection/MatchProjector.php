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
        // import_source_id is added here once the table exists (milestone 4)
        return ['team_id' => 'team', 'pitch_id' => 'pitch'];
    }

    protected function columns(): array
    {
        return [
            'team_id', 'anstoss', 'gegner', 'heimspiel', 'ort_text', 'pitch_id',
            'status', 'import_source_id', 'ics_uid', 'ics_sequence', 'sync_hash',
        ];
    }
}
