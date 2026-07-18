<?php

declare(strict_types=1);

namespace App\Service\Stammdaten;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Repository\BereichRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

final readonly class BereichService
{
    public function __construct(
        private EventStore $eventStore,
        private BereichRepository $bereiche,
    ) {
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function create(array $input, EventContext $context): int
    {
        $payload = $this->validate($input);

        return $this->eventStore
            ->append(AggregateType::Bereich, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function update(int $id, array $input, EventContext $context): void
    {
        if ($this->bereiche->find($id) === null) {
            throw new ValidationException(['id' => 'Bereich nicht gefunden.']);
        }

        $payload = $this->validate($input);
        $this->eventStore->append(AggregateType::Bereich, $id, EventType::Updated, $payload, $context);
    }

    /**
     * Refused while teams still reference this bereich (mirrors VenueService
     * refusing to delete a venue with pitches); deactivating instead keeps
     * the bereich assignable to nothing new while existing teams/history
     * keep working (same semantics as team.aktiv).
     */
    public function delete(int $id, EventContext $context): void
    {
        $bereich = $this->bereiche->find($id);
        if ($bereich === null) {
            throw new ValidationException(['id' => 'Bereich nicht gefunden.']);
        }
        if ($this->bereiche->countTeams($id) > 0) {
            throw new ValidationException([
                'id' => 'Bereich hat noch Teams und kann nicht gelöscht werden. Stattdessen deaktivieren.',
            ]);
        }

        $this->eventStore->append(AggregateType::Bereich, $id, EventType::Deleted, [
            'name' => (string) $bereich['name'],
            'kuerzel' => (string) $bereich['kuerzel'],
            'sortierung' => (int) $bereich['sortierung'],
            'aktiv' => (bool) $bereich['aktiv'],
        ], $context);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Name ist erforderlich (max. 100 Zeichen).';
        }

        $kuerzel = trim((string) ($input['kuerzel'] ?? ''));
        if ($kuerzel === '' || mb_strlen($kuerzel) > 10) {
            $errors['kuerzel'] = 'Kürzel ist erforderlich (max. 10 Zeichen).';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name,
            'kuerzel' => $kuerzel,
            'sortierung' => (int) ($input['sortierung'] ?? 0),
            'aktiv' => ($input['aktiv'] ?? '') !== '',
        ];
    }
}
