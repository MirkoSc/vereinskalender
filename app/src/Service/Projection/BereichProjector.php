<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class BereichProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Bereich;
    }

    public function tableName(): string
    {
        return 'bereich';
    }

    public function references(): array
    {
        return [];
    }

    protected function columns(): array
    {
        return ['name', 'kuerzel', 'sortierung', 'aktiv'];
    }
}
