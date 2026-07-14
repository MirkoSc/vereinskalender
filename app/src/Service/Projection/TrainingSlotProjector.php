<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class TrainingSlotProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::TrainingSlot;
    }

    public function tableName(): string
    {
        return 'training_slot';
    }

    public function references(): array
    {
        return ['team_id' => 'team', 'pitch_id' => 'pitch'];
    }

    protected function columns(): array
    {
        return ['team_id', 'pitch_id', 'wochentag', 'beginn', 'ende', 'gueltig_ab', 'gueltig_bis'];
    }
}
