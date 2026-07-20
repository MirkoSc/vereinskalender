<?php

declare(strict_types=1);

namespace App\Service\Stammdaten;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Repository\SportheimRaumRepository;
use App\Repository\SportheimRepository;
use App\Repository\VenueRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

final readonly class SportheimService
{
    public function __construct(
        private EventStore $eventStore,
        private SportheimRepository $sportheime,
        private SportheimRaumRepository $raeume,
        private VenueRepository $venues,
    ) {
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function create(array $input, EventContext $context): int
    {
        $payload = $this->validate($input);

        return $this->eventStore
            ->append(AggregateType::Sportheim, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function update(int $id, array $input, EventContext $context): void
    {
        if ($this->sportheime->find($id) === null) {
            throw new ValidationException(['id' => 'Sportheim nicht gefunden.']);
        }

        $payload = $this->validate($input);
        $this->eventStore->append(AggregateType::Sportheim, $id, EventType::Updated, $payload, $context);
    }

    /**
     * Refused while rooms, pitches, or vermietungen still reference this
     * Sportheim (mirrors BereichService/VenueService): deactivating instead
     * keeps history and existing references intact. Rooms are guarded too
     * (not just pitches/vermietungen) because deleting a Sportheim with
     * rooms would orphan them on replay (SportheimRaumProjector::references()
     * would then find no sportheim row and skip the room event).
     */
    public function delete(int $id, EventContext $context): void
    {
        $sportheim = $this->sportheime->find($id);
        if ($sportheim === null) {
            throw new ValidationException(['id' => 'Sportheim nicht gefunden.']);
        }
        if ($this->sportheime->countRaeume($id) > 0
            || $this->sportheime->countPitches($id) > 0
            || $this->sportheime->countVermietungen($id) > 0
        ) {
            throw new ValidationException([
                'id' => 'Sportheim wird noch verwendet (Räume, Plätze oder Vermietungen) und kann nicht gelöscht werden. Stattdessen deaktivieren.',
            ]);
        }

        $this->eventStore->append(AggregateType::Sportheim, $id, EventType::Deleted, [
            'venue_id' => (int) $sportheim['venue_id'],
            'name' => (string) $sportheim['name'],
            'adresse' => $sportheim['adresse'] !== null ? (string) $sportheim['adresse'] : null,
            'sortierung' => (int) $sportheim['sortierung'],
            'aktiv' => (bool) $sportheim['aktiv'],
        ], $context);
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function addRaum(int $sportheimId, array $input, EventContext $context): int
    {
        if ($this->sportheime->find($sportheimId) === null) {
            throw new ValidationException(['sportheim_id' => 'Sportheim nicht gefunden.']);
        }

        $payload = $this->validateRaum($input, $sportheimId);

        return $this->eventStore
            ->append(AggregateType::SportheimRaum, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function updateRaum(int $id, array $input, EventContext $context): void
    {
        $raum = $this->raeume->find($id);
        if ($raum === null) {
            throw new ValidationException(['id' => 'Raum nicht gefunden.']);
        }

        $payload = $this->validateRaum($input, (int) $raum['sportheim_id']);
        $this->eventStore->append(AggregateType::SportheimRaum, $id, EventType::Updated, $payload, $context);
    }

    /**
     * Refused while a vermietung still references this room; deactivating
     * instead keeps the room's history intact.
     */
    public function deleteRaum(int $id, EventContext $context): void
    {
        $raum = $this->raeume->find($id);
        if ($raum === null) {
            throw new ValidationException(['id' => 'Raum nicht gefunden.']);
        }
        if ($this->raeume->isUsedByVermietung($id)) {
            throw new ValidationException([
                'id' => 'Raum wird noch von einer Vermietung verwendet und kann nicht gelöscht werden. Stattdessen deaktivieren.',
            ]);
        }

        $this->eventStore->append(AggregateType::SportheimRaum, $id, EventType::Deleted, [
            'sportheim_id' => (int) $raum['sportheim_id'],
            'name' => (string) $raum['name'],
            'kuerzel' => (string) $raum['kuerzel'],
            'sortierung' => (int) $raum['sortierung'],
            'aktiv' => (bool) $raum['aktiv'],
        ], $context);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input): array
    {
        $errors = [];

        $venueId = (int) ($input['venue_id'] ?? 0);
        if ($venueId <= 0 || $this->venues->find($venueId) === null) {
            $errors['venue_id'] = 'Bitte einen vorhandenen Heimverein wählen.';
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Name ist erforderlich (max. 100 Zeichen).';
        }

        $adresse = trim((string) ($input['adresse'] ?? ''));
        if (mb_strlen($adresse) > 255) {
            $errors['adresse'] = 'Adresse darf max. 255 Zeichen lang sein.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'venue_id' => $venueId,
            'name' => $name,
            'adresse' => $adresse === '' ? null : $adresse,
            'sortierung' => (int) ($input['sortierung'] ?? 0),
            'aktiv' => ($input['aktiv'] ?? '') !== '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validateRaum(array $input, int $sportheimId): array
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
            'sportheim_id' => $sportheimId,
            'name' => $name,
            'kuerzel' => $kuerzel,
            'sortierung' => (int) ($input['sortierung'] ?? 0),
            'aktiv' => ($input['aktiv'] ?? '') !== '',
        ];
    }
}
