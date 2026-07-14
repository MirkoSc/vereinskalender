<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class TeamProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Team;
    }

    public function tableName(): string
    {
        return 'team';
    }

    public function references(): array
    {
        return [];
    }

    protected function columns(): array
    {
        return ['bereich', 'name', 'kuerzel', 'farbe', 'aktiv', 'sortierung'];
    }
}
