<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class PitchProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Pitch;
    }

    public function tableName(): string
    {
        return 'pitch';
    }

    public function references(): array
    {
        return ['venue_id' => 'venue'];
    }

    protected function columns(): array
    {
        return ['venue_id', 'name', 'typ', 'flutlicht', 'adresse', 'sortierung'];
    }
}
