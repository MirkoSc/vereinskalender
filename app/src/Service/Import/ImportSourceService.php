<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Repository\ImportSourceRepository;
use App\Repository\MatchRepository;
use App\Repository\TeamRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Admin CRUD for import sources (event-based; the run status columns are
 * technical and never touched here).
 *
 * delete() deliberately never touches the source's match rows - deleting
 * dozens of matches inline would turn one admin click into an unbounded
 * write, and a re-created source with the same team just resumes the sync
 * (import_source_id is a fresh aggregate id either way). That leaves
 * matches whose import_source_id points at nothing behind
 * (MatchRepository::findOrphanedImports()); deleteOrphanedMatches() is the
 * admin's one way to clear them, with no time boundary - unlike
 * IcsImportService::resetSource(), there is no feed left to re-fetch them
 * from, so a past orphan is gone for good just like a future one.
 */
final readonly class ImportSourceService
{
    public function __construct(
        private EventStore $eventStore,
        private ImportSourceRepository $sources,
        private TeamRepository $teams,
        private MatchRepository $matches,
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

    /**
     * @return int matches this source's team still has under the deleted
     *         source's id - orphaned as of this call, cleared via
     *         deleteOrphanedMatches()
     */
    public function delete(int $id, EventContext $context): int
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

        return count($this->matches->findBySource($id));
    }

    /**
     * Every match whose import_source_id points at a source that no longer
     * exists, removed via a Deleted event each. The payload keeps the row's
     * own (dead) import_source_id - a full-picture payload has to describe
     * the row, and replay never re-checks a Deleted event's references
     * (Replayer::applyRow()), so a stale id there is harmless.
     */
    public function deleteOrphanedMatches(EventContext $context): int
    {
        $deleted = 0;

        foreach ($this->matches->findOrphanedImports() as $match) {
            $this->eventStore->append(AggregateType::Match, (int) $match['id'], EventType::Deleted, [
                'team_id' => (int) $match['team_id'],
                'anstoss' => (string) $match['anstoss'],
                'ende' => $match['ende'] !== null ? (string) $match['ende'] : null,
                'gegner' => (string) $match['gegner'],
                'heimspiel' => (int) $match['heimspiel'] === 1,
                'spielfrei' => (int) $match['spielfrei'] === 1,
                'ort_text' => (string) $match['ort_text'],
                'pitch_id' => $match['pitch_id'] !== null ? (int) $match['pitch_id'] : null,
                'pitch_manuell' => (int) $match['pitch_manuell'] === 1,
                'status' => (string) $match['status'],
                'import_source_id' => (int) $match['import_source_id'],
                'ics_uid' => (string) $match['ics_uid'],
                'ics_sequence' => (int) $match['ics_sequence'],
                'sync_hash' => (string) $match['sync_hash'],
            ], $context);
            $deleted++;
        }

        return $deleted;
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
