<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class VenueBegriffProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::VenueBegriff;
    }

    public function tableName(): string
    {
        return 'venue_begriff';
    }

    public function references(): array
    {
        return ['venue_id' => 'venue'];
    }

    protected function columns(): array
    {
        return ['venue_id', 'begriff', 'sortierung'];
    }
}
