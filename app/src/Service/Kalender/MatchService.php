<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Manual pitch assignment for imported matches (CLAUDE.md section 7): the
 * concrete pitch is not in the ICS; the import pre-fills the venue default
 * and the assignment stays manually changeable - name-based, as an event.
 * The sync_hash does not cover pitch_id, so the next import run keeps the
 * manual assignment (as long as the location text is unchanged).
 */
final readonly class MatchService
{
    public function __construct(
        private EventStore $eventStore,
        private MatchRepository $matches,
        private PitchRepository $pitches,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function assignPitch(int $matchId, array $input, EventContext $context): void
    {
        $match = $this->matches->find($matchId);
        if ($match === null) {
            throw new ValidationException(['id' => 'Spiel nicht gefunden.']);
        }

        $pitchId = null;
        $raw = trim((string) ($input['pitch_id'] ?? ''));
        if ($raw !== '') {
            $pitchId = (int) $raw;
            if ($this->pitches->find($pitchId) === null) {
                throw new ValidationException(['pitch_id' => 'Platz nicht gefunden.']);
            }
        }

        $payload = [
            'team_id' => (int) $match['team_id'],
            'anstoss' => (string) $match['anstoss'],
            'gegner' => (string) $match['gegner'],
            'heimspiel' => (int) $match['heimspiel'] === 1,
            'ort_text' => (string) $match['ort_text'],
            'pitch_id' => $pitchId,
            'status' => (string) $match['status'],
            'import_source_id' => $match['import_source_id'] !== null ? (int) $match['import_source_id'] : null,
            'ics_uid' => (string) $match['ics_uid'],
            'ics_sequence' => (int) $match['ics_sequence'],
            'sync_hash' => (string) $match['sync_hash'],
        ];

        $this->eventStore->append(AggregateType::Match, $matchId, EventType::Updated, $payload, $context);
    }
}
