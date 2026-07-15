<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;
use App\Domain\Palette;

final class PitchProjector extends TableProjector
{
    public function aggregateType(): AggregateType
    {
        return AggregateType::Pitch;
    }

    public function tableName(): string
    {
        return 'pitch';
    }

    public function references(): array
    {
        return ['venue_id' => 'venue'];
    }

    /**
     * Pitch events written before migration 009 carry no color; upcast them
     * deterministically to the same default the migration backfills onto
     * existing rows (CLAUDE.md section 5).
     */
    public function normalizePayload(array $payload): array
    {
        $farbe = (string) ($payload['farbe'] ?? '');

        return [
            ...$payload,
            'farbe' => $farbe === '' ? Palette::PITCH_DEFAULT : $farbe,
        ];
    }

    protected function columns(): array
    {
        return ['venue_id', 'name', 'farbe', 'typ', 'flutlicht', 'adresse', 'sortierung'];
    }
}
