<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class SportheimProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Sportheim;
    }

    public function tableName(): string
    {
        return 'sportheim';
    }

    public function references(): array
    {
        return ['venue_id' => 'venue'];
    }

    protected function columns(): array
    {
        return ['venue_id', 'name', 'adresse', 'sortierung', 'aktiv'];
    }
}
