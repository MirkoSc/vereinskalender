<?php

declare(strict_types=1);

namespace App\Service\Projection;

use App\Domain\AggregateType;

final class TeamProjector extends TableProjector
{
    /** @var array<string, int>|null kuerzel => bereich aggregate id, lazily built from the immutable system-seeded bereich CREATED events */
    private ?array $legacyBereichIds = null;

    public function aggregateType(): AggregateType
    {
        return AggregateType::Team;
    }

    public function tableName(): string
    {
        return 'team';
    }

    public function references(): array
    {
        // deliberately NOT bereich_id: legacy team events upcast it from the
        // event log directly (see normalizePayload), independent of replay
        // order relative to the bereich seed events, so a missing bereich
        // row must never cause a team event to be skipped
        return [];
    }

    /**
     * Team events written before Issue #27 carry only the string `bereich`
     * (the former fixed enum G/F/E/D/C/Herren). The upcast to `bereich_id`
     * must stay deterministic on replay even though team events interleave
     * with the (migration-seeded) bereich CREATED events in the log: it
     * resolves the enum string against the IMMUTABLE payloads of the
     * system-seeded bereich CREATED events read straight from the event
     * table, never against the live (renameable) `bereich` projection - so
     * renaming a bereich later can never change how an old team event
     * replays.
     */
    public function normalizePayload(array $payload): array
    {
        if (array_key_exists('bereich_id', $payload)) {
            return $payload;
        }

        return [
            ...$payload,
            'bereich_id' => $this->legacyBereichId((string) ($payload['bereich'] ?? '')),
        ];
    }

    protected function columns(): array
    {
        return ['bereich', 'bereich_id', 'name', 'kuerzel', 'farbe', 'aktiv', 'sortierung'];
    }

    private function legacyBereichId(string $kuerzel): ?int
    {
        if ($this->legacyBereichIds === null) {
            $this->legacyBereichIds = [];
            $stmt = $this->pdo->query(
                "SELECT aggregat_id, payload FROM event
                 WHERE aggregat_typ = 'bereich' AND event_typ = 'created' AND quelle = 'system' AND excluded_at IS NULL
                 ORDER BY id",
            );
            foreach ($stmt->fetchAll() as $row) {
                $rowPayload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
                $rowKuerzel = (string) ($rowPayload['kuerzel'] ?? '');
                // first (= earliest) match wins - the event log is immutable
                $this->legacyBereichIds[$rowKuerzel] ??= (int) $row['aggregat_id'];
            }
        }

        return $this->legacyBereichIds[$kuerzel] ?? null;
    }
}
