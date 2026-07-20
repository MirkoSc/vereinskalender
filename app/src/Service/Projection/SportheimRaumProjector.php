<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class SportheimRaumProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::SportheimRaum;
    }

    public function tableName(): string
    {
        return 'sportheim_raum';
    }

    public function references(): array
    {
        return ['sportheim_id' => 'sportheim'];
    }

    protected function columns(): array
    {
        return ['sportheim_id', 'name', 'kuerzel', 'sortierung', 'aktiv'];
    }
}
