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

final readonly class PitchService
{
    public function __construct(
        private EventStore $eventStore,
        private PitchRepository $pitches,
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
            ->append(AggregateType::Pitch, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function update(int $id, array $input, EventContext $context): void
    {
        if ($this->pitches->find($id) === null) {
            throw new ValidationException(['id' => 'Platz nicht gefunden.']);
        }

        $payload = $this->validate($input);
        $this->eventStore->append(AggregateType::Pitch, $id, EventType::Updated, $payload, $context);
    }

    public function delete(int $id, EventContext $context): void
    {
        $pitch = $this->pitches->find($id);
        if ($pitch === null) {
            throw new ValidationException(['id' => 'Platz nicht gefunden.']);
        }

        $stmt = $this->venues->findAll();
        foreach ($stmt as $venue) {
            if ((int) ($venue['default_pitch_id'] ?? 0) === $id) {
                throw new ValidationException([
                    'id' => sprintf(
                        'Platz ist Standard-Platz der Spielstätte „%s" und kann nicht gelöscht werden.',
                        (string) $venue['name'],
                    ),
                ]);
            }
        }

        $payload = [
            'venue_id' => (int) $pitch['venue_id'],
            'name' => (string) $pitch['name'],
            'kuerzel' => (string) $pitch['kuerzel'],
            'farbe' => (string) $pitch['farbe'],
            'typ' => (string) $pitch['typ'],
            'flutlicht' => (bool) $pitch['flutlicht'],
            'adresse' => $pitch['adresse'] !== null ? (string) $pitch['adresse'] : null,
            'sortierung' => (int) $pitch['sortierung'],
        ];
        $this->eventStore->append(AggregateType::Pitch, $id, EventType::Deleted, $payload, $context);
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
            $errors['venue_id'] = 'Bitte eine vorhandene Spielstätte wählen.';
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Name ist erforderlich (max. 100 Zeichen).';
        }

        $kuerzel = trim((string) ($input['kuerzel'] ?? ''));
        if ($kuerzel === '' || mb_strlen($kuerzel) > 10) {
            $errors['kuerzel'] = 'Kürzel ist erforderlich (max. 10 Zeichen).';
        }

        $typ = trim((string) ($input['typ'] ?? ''));
        if (mb_strlen($typ) > 50) {
            $errors['typ'] = 'Typ darf max. 50 Zeichen lang sein.';
        }

        $farbe = trim((string) ($input['farbe'] ?? ''));
        if (!Palette::isValid($farbe)) {
            $errors['farbe'] = 'Bitte eine Farbe aus der Palette wählen.';
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
            'kuerzel' => $kuerzel,
            'farbe' => $farbe,
            'typ' => $typ,
            'flutlicht' => ($input['flutlicht'] ?? '') !== '',
            // NULL = same address as the venue (CLAUDE.md section 4)
            'adresse' => $adresse === '' ? null : $adresse,
            'sortierung' => (int) ($input['sortierung'] ?? 0),
        ];
    }
}
