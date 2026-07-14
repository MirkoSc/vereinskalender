<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class SlotExceptionProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::SlotException;
    }

    public function tableName(): string
    {
        return 'slot_exception';
    }

    public function references(): array
    {
        return ['slot_id' => 'training_slot'];
    }

    protected function columns(): array
    {
        return ['slot_id', 'datum', 'grund'];
    }
}
