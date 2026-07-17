<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\TeamRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Manual pitch assignment for imported matches (CLAUDE.md section 6): the
 * concrete pitch is not in the ICS; the import pre-fills it from a
 * team_home_pitch rule or the venue default. Choosing a concrete pitch here
 * sets pitch_manuell=true, which the import then never touches again;
 * choosing the empty option resets to automatic (pitch_manuell=false), so
 * the next import run re-assigns by rule/default.
 *
 * Manually created matches (issue #12, CLAUDE.md section 3): friendlies and
 * tournaments, marked by import_source_id IS NULL. Public create/edit/delete
 * (Zugriffsebene 2), as events like every other write; only manual matches
 * are editable here - imported ones are rejected and stay the import's
 * responsibility. A chosen pitch implies heimspiel; without a pitch,
 * ort_text is required and heimspiel follows the VenueMatcher, same as at
 * import time. Editing bumps ics_sequence so calendar subscriptions treat a
 * relocation as an update, not a duplicate.
 */
final readonly class MatchService
{
    public function __construct(
        private EventStore $eventStore,
        private MatchRepository $matches,
        private PitchRepository $pitches,
        private TeamRepository $teams,
        private VenueMatcher $venueMatcher,
        private BookingService $booking,
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
            ...self::rowPayload($match),
            'pitch_id' => $pitchId,
            'pitch_manuell' => $pitchId !== null,
        ];

        $this->eventStore->append(AggregateType::Match, $matchId, EventType::Updated, $payload, $context);
    }

    /**
     * Dry run for the create/edit dialog: same validation + conflict check
     * as the corresponding write, without appending an event.
     *
     * @param array<string, mixed> $input
     */
    public function check(array $input, ?int $matchId): ConflictCheckResult
    {
        $this->assertManual($matchId);

        $payload = $this->validate($input, isUpdate: $matchId !== null);

        return $this->runConflictCheck($payload, $matchId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, warnings: list<string>}
     */
    public function createMatch(array $input, EventContext $context): array
    {
        $payload = $this->validate($input, isUpdate: false);

        $result = $this->runConflictCheck($payload, null);
        if ($result->hasConflicts()) {
            throw new ConflictException($result->conflicts, $result->details);
        }

        $id = $this->eventStore
            ->append(AggregateType::Match, null, EventType::Created, $payload, $context)
            ->aggregateId;

        return ['id' => $id, 'warnings' => $result->warnings];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{warnings: list<string>}
     */
    public function updateMatch(int $matchId, array $input, EventContext $context): array
    {
        $match = $this->assertManual($matchId);

        $payload = $this->validate($input, isUpdate: true);
        // a relocation/cancellation must reach subscribed calendars as an
        // update of the same UID, not a silently identical DTSTAMP
        $payload['ics_sequence'] = (int) $match['ics_sequence'] + 1;

        $result = $this->runConflictCheck($payload, $matchId);
        if ($result->hasConflicts()) {
            throw new ConflictException($result->conflicts, $result->details);
        }

        $this->eventStore->append(AggregateType::Match, $matchId, EventType::Updated, $payload, $context);

        return ['warnings' => $result->warnings];
    }

    public function deleteMatch(int $matchId, EventContext $context): void
    {
        $match = $this->assertManual($matchId);

        $this->eventStore->append(AggregateType::Match, $matchId, EventType::Deleted, self::rowPayload($match), $context);
    }

    /**
     * @return array<string, mixed> the match row
     */
    private function assertManual(?int $matchId): array
    {
        if ($matchId === null) {
            return [];
        }

        $match = $this->matches->find($matchId);
        if ($match === null) {
            throw new ValidationException(['id' => 'Spiel nicht gefunden.']);
        }
        if ($match['import_source_id'] !== null) {
            throw new ValidationException(['id' => 'Importierte Spiele können nur über den Import geändert werden.']);
        }

        return $match;
    }

    /**
     * @param array<string, mixed> $payload validated match payload
     */
    private function runConflictCheck(array $payload, ?int $ignoreMatchId): ConflictCheckResult
    {
        if ((string) $payload['status'] === 'abgesagt') {
            return new ConflictCheckResult([], []);
        }

        return $this->booking->checkMatch(
            $payload['pitch_id'],
            new \DateTimeImmutable((string) $payload['anstoss']),
            MatchDuration::effectiveEnd((string) $payload['anstoss'], $payload['ende']),
            $ignoreMatchId,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input, bool $isUpdate): array
    {
        $errors = [];

        $teamId = (int) ($input['team_id'] ?? 0);
        if ($teamId <= 0 || $this->teams->find($teamId) === null) {
            $errors['team_id'] = 'Bitte ein vorhandenes Team wählen.';
        }

        $datum = self::parseDate((string) ($input['datum'] ?? ''));
        $anstossZeit = self::parseTime((string) ($input['anstoss'] ?? ''));
        $anstoss = null;
        if ($datum === null || $anstossZeit === null) {
            $errors['anstoss'] = 'Bitte Datum und Uhrzeit für den Anstoß angeben.';
        } else {
            $anstoss = $datum . ' ' . $anstossZeit;
        }

        $ende = null;
        $endeRaw = trim((string) ($input['ende'] ?? ''));
        if ($endeRaw !== '') {
            $endeZeit = self::parseTime($endeRaw);
            if ($endeZeit === null) {
                $errors['ende'] = 'Bitte eine gültige Endzeit angeben (HH:MM).';
            } elseif ($datum !== null) {
                $kandidat = $datum . ' ' . $endeZeit;
                if ($anstoss !== null && $kandidat <= $anstoss) {
                    $errors['ende'] = 'Das Ende muss nach dem Anstoß liegen.';
                } else {
                    $ende = $kandidat;
                }
            }
        }

        $gegner = mb_substr(trim((string) ($input['gegner'] ?? '')), 0, 150);
        if ($gegner === '') {
            $errors['gegner'] = 'Bitte Gegner oder Titel angeben.';
        }

        $pitchId = null;
        $pitchRaw = trim((string) ($input['pitch_id'] ?? ''));
        if ($pitchRaw !== '') {
            $pitchId = (int) $pitchRaw;
            if ($this->pitches->find($pitchId) === null) {
                $errors['pitch_id'] = 'Platz nicht gefunden.';
                $pitchId = null;
            }
        }

        $ortText = mb_substr(trim((string) ($input['ort_text'] ?? '')), 0, 255);
        if ($pitchId === null && $ortText === '') {
            $errors['ort_text'] = 'Bitte einen Platz wählen oder einen Ort angeben.';
        }

        $status = 'geplant';
        if ($isUpdate) {
            $status = (string) ($input['status'] ?? 'geplant');
            if (!in_array($status, ['geplant', 'abgesagt'], true)) {
                $errors['status'] = 'Unbekannter Status.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $heimspiel = $pitchId !== null || $this->venueMatcher->match($ortText) !== null;

        return [
            'team_id' => $teamId,
            'anstoss' => $anstoss,
            'ende' => $ende,
            'gegner' => $gegner,
            'heimspiel' => $heimspiel,
            'ort_text' => $ortText,
            'pitch_id' => $pitchId,
            'pitch_manuell' => $pitchId !== null,
            'status' => $status,
            'import_source_id' => null,
            'ics_uid' => '',
            'ics_sequence' => 0,
            'sync_hash' => '',
        ];
    }

    /**
     * Full-picture payload from a stored match row (CLAUDE.md section 4:
     * events carry the whole target state, never a diff).
     *
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private static function rowPayload(array $match): array
    {
        return [
            'team_id' => (int) $match['team_id'],
            'anstoss' => (string) $match['anstoss'],
            'ende' => $match['ende'] !== null ? (string) $match['ende'] : null,
            'gegner' => (string) $match['gegner'],
            'heimspiel' => (int) $match['heimspiel'] === 1,
            'ort_text' => (string) $match['ort_text'],
            'pitch_id' => $match['pitch_id'] !== null ? (int) $match['pitch_id'] : null,
            'pitch_manuell' => (int) $match['pitch_manuell'] === 1,
            'status' => (string) $match['status'],
            'import_source_id' => $match['import_source_id'] !== null ? (int) $match['import_source_id'] : null,
            'ics_uid' => (string) $match['ics_uid'],
            'ics_sequence' => (int) $match['ics_sequence'],
            'sync_hash' => (string) $match['sync_hash'],
        ];
    }

    private static function parseTime(string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', trim($value), $m) === 1
            ? $m[1] . ':' . $m[2] . ':00'
            : null;
    }

    private static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$y, $m, $d] = array_map(intval(...), explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
