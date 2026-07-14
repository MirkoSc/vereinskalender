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
        return ['team_ids' => 'team', 'pitch_id' => 'pitch'];
    }

    /**
     * Slots carry 1..n teams and 1..n weekdays since migration 008; events
     * written before that have singular team_id/wochentag. The legacy
     * single-value columns keep the first list element so a rollback to the
     * previous release still reads sensible data.
     */
    public function normalizePayload(array $payload): array
    {
        $teamIds = array_values(array_map(intval(...), (array) ($payload['team_ids'] ?? [$payload['team_id'] ?? 0])));
        $wochentage = array_values(array_map(intval(...), (array) ($payload['wochentage'] ?? [$payload['wochentag'] ?? 0])));

        return [
            ...$payload,
            'team_ids' => $teamIds,
            'wochentage' => $wochentage,
            'team_id' => $teamIds[0] ?? 0,
            'wochentag' => $wochentage[0] ?? 0,
        ];
    }

    protected function columns(): array
    {
        return ['team_ids', 'wochentage', 'team_id', 'pitch_id', 'wochentag', 'beginn', 'ende', 'gueltig_ab', 'gueltig_bis'];
    }
}
