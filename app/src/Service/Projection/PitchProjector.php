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
        return ['venue_id' => 'venue', 'sportheim_id' => 'sportheim'];
    }

    /**
     * Pitch events written before migration 009 carry no color, events
     * before migration 011 carry no kuerzel, and events before migration 014
     * carry no sportheim_id; upcast all deterministically to the same
     * defaults the migrations backfill onto existing rows (CLAUDE.md
     * section 5). sportheim_id is nullable (not every pitch is at a
     * clubhouse), so a NULL upcast is safe - the Replayer skips NULL FK
     * values during the reference check.
     */
    public function normalizePayload(array $payload): array
    {
        $farbe = (string) ($payload['farbe'] ?? '');

        return [
            ...$payload,
            'farbe' => $farbe === '' ? Palette::PITCH_DEFAULT : $farbe,
            'kuerzel' => (string) ($payload['kuerzel'] ?? ''),
            'sportheim_id' => $payload['sportheim_id'] ?? null,
        ];
    }

    protected function columns(): array
    {
        return ['venue_id', 'name', 'kuerzel', 'farbe', 'typ', 'flutlicht', 'adresse', 'sortierung', 'sportheim_id'];
    }
}
