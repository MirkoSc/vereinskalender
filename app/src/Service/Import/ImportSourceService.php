<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Repository\ImportSourceRepository;
use App\Repository\TeamRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Admin CRUD for import sources (event-based; the run status columns are
 * technical and never touched here).
 */
final readonly class ImportSourceService
{
    public function __construct(
        private EventStore $eventStore,
        private ImportSourceRepository $sources,
        private TeamRepository $teams,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, EventContext $context): int
    {
        $payload = $this->validate($input);

        return $this->eventStore
            ->append(AggregateType::ImportSource, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input, EventContext $context): void
    {
        if ($this->sources->find($id) === null) {
            throw new ValidationException(['id' => 'Import-Quelle nicht gefunden.']);
        }

        $payload = $this->validate($input);
        $this->eventStore->append(AggregateType::ImportSource, $id, EventType::Updated, $payload, $context);
    }

    public function delete(int $id, EventContext $context): void
    {
        $source = $this->sources->find($id);
        if ($source === null) {
            throw new ValidationException(['id' => 'Import-Quelle nicht gefunden.']);
        }

        $this->eventStore->append(AggregateType::ImportSource, $id, EventType::Deleted, [
            'team_id' => (int) $source['team_id'],
            'ics_url' => (string) $source['ics_url'],
            'aktiv' => (int) $source['aktiv'] === 1,
        ], $context);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        $errors = [];

        $teamId = (int) ($input['team_id'] ?? 0);
        if ($teamId <= 0 || $this->teams->find($teamId) === null) {
            $errors['team_id'] = 'Bitte ein vorhandenes Team wählen.';
        }

        $icsUrl = trim((string) ($input['ics_url'] ?? ''));
        $scheme = strtolower((string) parse_url($icsUrl, PHP_URL_SCHEME));
        if ($icsUrl === '' || mb_strlen($icsUrl) > 500
            || !in_array($scheme, ['http', 'https', 'webcal'], true)) {
            $errors['ics_url'] = 'Bitte eine gültige ICS-URL angeben (http/https/webcal).';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // calendar apps hand out webcal:// links; fetching needs https
        if ($scheme === 'webcal') {
            $icsUrl = 'https' . substr($icsUrl, strlen('webcal'));
        }

        return [
            'team_id' => $teamId,
            'ics_url' => $icsUrl,
            'aktiv' => ($input['aktiv'] ?? '') !== '',
        ];
    }
}
