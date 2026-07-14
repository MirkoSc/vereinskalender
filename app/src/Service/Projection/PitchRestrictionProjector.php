<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class PitchRestrictionProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::PitchRestriction;
    }

    public function tableName(): string
    {
        return 'pitch_restriction';
    }

    public function references(): array
    {
        return ['pitch_id' => 'pitch'];
    }

    protected function columns(): array
    {
        return ['pitch_id', 'von', 'bis', 'art', 'grund'];
    }
}
