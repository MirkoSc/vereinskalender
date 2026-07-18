<?php

declare(strict_types=1);

namespace App\Service\Stammdaten;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Domain\Palette;
use App\Repository\BereichRepository;
use App\Repository\TeamRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

final readonly class TeamService
{
    public function __construct(
        private EventStore $eventStore,
        private TeamRepository $teams,
        private BereichRepository $bereiche,
    ) {
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function create(array $input, EventContext $context): int
    {
        $payload = $this->validate($input, null);

        return $this->eventStore
            ->append(AggregateType::Team, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function update(int $id, array $input, EventContext $context): void
    {
        $team = $this->teams->find($id);
        if ($team === null) {
            throw new ValidationException(['id' => 'Team nicht gefunden.']);
        }

        $currentBereichId = $team['bereich_id'] !== null ? (int) $team['bereich_id'] : null;
        $payload = $this->validate($input, $currentBereichId);
        $this->eventStore->append(AggregateType::Team, $id, EventType::Updated, $payload, $context);
    }

    public function delete(int $id, EventContext $context): void
    {
        $team = $this->teams->find($id);
        if ($team === null) {
            throw new ValidationException(['id' => 'Team nicht gefunden.']);
        }

        // full picture of the last state, useful for the event history
        $payload = [
            'bereich' => (string) $team['bereich'],
            'bereich_id' => $team['bereich_id'] !== null ? (int) $team['bereich_id'] : null,
            'name' => (string) $team['name'],
            'kuerzel' => (string) $team['kuerzel'],
            'farbe' => (string) $team['farbe'],
            'aktiv' => (bool) $team['aktiv'],
            'sortierung' => (int) $team['sortierung'],
        ];
        $this->eventStore->append(AggregateType::Team, $id, EventType::Deleted, $payload, $context);
    }

    /**
     * @param array<string, mixed> $input
     * @param int|null $currentBereichId the team's bereich before this write
     *        (null for a new team): an inactive bereich stays valid for a
     *        team that already has it (like team.aktiv - history keeps
     *        working, only NEW assignments to an inactive bereich are
     *        blocked)
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input, ?int $currentBereichId): array
    {
        $errors = [];

        $bereichId = (int) ($input['bereich_id'] ?? 0);
        $bereich = $bereichId > 0 ? $this->bereiche->find($bereichId) : null;
        if ($bereich === null) {
            $errors['bereich_id'] = 'Bitte einen gültigen Bereich wählen.';
        } elseif ((int) $bereich['aktiv'] !== 1 && $bereichId !== $currentBereichId) {
            $errors['bereich_id'] = 'Bitte einen aktiven Bereich wählen.';
        }

        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'Name ist erforderlich (max. 100 Zeichen).';
        }

        $kuerzel = trim((string) ($input['kuerzel'] ?? ''));
        if ($kuerzel === '' || mb_strlen($kuerzel) > 10) {
            $errors['kuerzel'] = 'Kürzel ist erforderlich (max. 10 Zeichen).';
        }

        $farbe = trim((string) ($input['farbe'] ?? ''));
        if (!Palette::isValid($farbe)) {
            $errors['farbe'] = 'Bitte eine Farbe aus der Palette wählen.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'bereich_id' => $bereichId,
            'bereich' => (string) $bereich['kuerzel'],
            'name' => $name,
            'kuerzel' => $kuerzel,
            'farbe' => $farbe,
            'aktiv' => ($input['aktiv'] ?? '') !== '',
            'sortierung' => (int) ($input['sortierung'] ?? 0),
        ];
    }
}
