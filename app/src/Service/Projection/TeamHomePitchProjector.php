<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class TeamHomePitchProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::TeamHomePitch;
    }

    public function tableName(): string
    {
        return 'team_home_pitch';
    }

    public function references(): array
    {
        return ['team_id' => 'team', 'pitch_id' => 'pitch'];
    }

    protected function columns(): array
    {
        return ['team_id', 'pitch_id', 'gueltig_ab', 'gueltig_bis'];
    }
}
