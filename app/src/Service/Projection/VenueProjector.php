<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class VenueProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Venue;
    }

    public function tableName(): string
    {
        return 'venue';
    }

    public function references(): array
    {
        // nullable reference: only checked when the payload value is set
        return ['default_pitch_id' => 'pitch'];
    }

    protected function columns(): array
    {
        return ['name', 'farbe', 'adresse', 'default_pitch_id', 'sortierung'];
    }
}
