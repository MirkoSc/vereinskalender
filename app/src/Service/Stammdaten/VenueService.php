<?php

declare(strict_types=1);

namespace App\Service\Stammdaten;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Domain\Palette;
use App\Repository\PitchRepository;
use App\Repository\VenueRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

final readonly class VenueService
{
    public function __construct(
        private \PDO $pdo,
        private EventStore $eventStore,
        private VenueRepository $venues,
        private PitchRepository $pitches,
    ) {
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function create(array $input, EventContext $context): int
    {
        $payload = $this->validate($input, null);

        return $this->eventStore
            ->append(AggregateType::Venue, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function update(int $id, array $input, EventContext $context): void
    {
        if ($this->venues->find($id) === null) {
            throw new ValidationException(['id' => 'Spielstätte nicht gefunden.']);
        }

        $payload = $this->validate($input, $id);
        $this->eventStore->append(AggregateType::Venue, $id, EventType::Updated, $payload, $context);
    }

    /**
     * Deletes the venue together with its begriffe (one transaction, one
     * event per aggregate). Refused while pitches still belong to it.
     */
    public function delete(int $id, EventContext $context): void
    {
        $venue = $this->venues->find($id);
        if ($venue === null) {
            throw new ValidationException(['id' => 'Spielstätte nicht gefunden.']);
        }
        if ($this->pitches->countByVenue($id) > 0) {
            throw new ValidationException([
                'id' => 'Spielstätte hat noch Plätze und kann nicht gelöscht werden.',
            ]);
        }

        $this->pdo->beginTransaction();
        try {
            foreach ($this->venues->findBegriffe($id) as $begriff) {
                $this->eventStore->append(
                    AggregateType::VenueBegriff,
                    (int) $begriff['id'],
                    EventType::Deleted,
                    [
                        'venue_id' => (int) $begriff['venue_id'],
                        'begriff' => (string) $begriff['begriff'],
                        'sortierung' => (int) $begriff['sortierung'],
                    ],
                    $context,
                );
            }

            $this->eventStore->append(
                AggregateType::Venue,
                $id,
                EventType::Deleted,
                [
                    'name' => (string) $venue['name'],
                    'farbe' => (string) $venue['farbe'],
                    'adresse' => (string) $venue['adresse'],
                    'default_pitch_id' => $venue['default_pitch_id'] !== null
                        ? (int) $venue['default_pitch_id']
                        : null,
                    'sortierung' => (int) $venue['sortierung'],
                ],
                $context,
            );

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function addBegriff(int $venueId, array $input, EventContext $context): int
    {
        if ($this->venues->find($venueId) === null) {
            throw new ValidationException(['venue_id' => 'Spielstätte nicht gefunden.']);
        }

        $begriff = trim((string) ($input['begriff'] ?? ''));
        if ($begriff === '' || mb_strlen($begriff) > 100) {
            throw new ValidationException(['begriff' => 'Begriff ist erforderlich (max. 100 Zeichen).']);
        }

        $payload = [
            'venue_id' => $venueId,
            'begriff' => $begriff,
            'sortierung' => (int) ($input['sortierung'] ?? 0),
        ];

        return $this->eventStore
            ->append(AggregateType::VenueBegriff, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    public function deleteBegriff(int $id, EventContext $context): void
    {
        $begriff = $this->venues->findBegriff($id);
        if ($begriff === null) {
            throw new ValidationException(['id' => 'Begriff nicht gefunden.']);
        }

        $this->eventStore->append(
            AggregateType::VenueBegriff,
            $id,
            EventType::Deleted,
            [
                'venue_id' => (int) $begriff['venue_id'],
                'begriff' => (string) $begriff['begriff'],
                'sortierung' => (int) $begriff['sortierung'],
            ],
            $context,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input, ?int $venueId): array
    {
        $errors = [];

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Name ist erforderlich (max. 100 Zeichen).';
        }

        $farbe = trim((string) ($input['farbe'] ?? ''));
        if (!Palette::isValid($farbe)) {
            $errors['farbe'] = 'Bitte eine Farbe aus der Palette wählen.';
        }

        $adresse = trim((string) ($input['adresse'] ?? ''));
        if ($adresse === '' || mb_strlen($adresse) > 255) {
            $errors['adresse'] = 'Adresse ist erforderlich (max. 255 Zeichen).';
        }

        $defaultPitchId = null;
        $rawDefaultPitch = trim((string) ($input['default_pitch_id'] ?? ''));
        if ($rawDefaultPitch !== '') {
            $pitch = $this->pitches->find((int) $rawDefaultPitch);
            if ($pitch === null || ($venueId !== null && (int) $pitch['venue_id'] !== $venueId)) {
                $errors['default_pitch_id'] = 'Der Standard-Platz muss zu dieser Spielstätte gehören.';
            } else {
                $defaultPitchId = (int) $rawDefaultPitch;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'name' => $name,
            'farbe' => $farbe,
            'adresse' => $adresse,
            'default_pitch_id' => $defaultPitchId,
            'sortierung' => (int) ($input['sortierung'] ?? 0),
        ];
    }
}
