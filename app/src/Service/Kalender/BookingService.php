<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Domain\RestrictionArt;
use App\Repository\MatchRepository;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Repository\SlotExceptionRepository;
use App\Repository\TeamRepository;
use App\Repository\TrainingSlotRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Conflict checking and the write path for pitch occupancy (CLAUDE.md
 * section 12): saving a slot expands existing slots + matches + restrictions
 * of the same pitch within the validity range and checks for overlaps.
 * 'gesperrt' restrictions reject the booking, 'eingeschraenkt' allows it
 * with a warning. The same expansion logic feeds the availability view.
 *
 * A slot carries 1..n teams (joint training) and 1..n weekdays. Editing
 * supports three scopes (edit_scope): 'alle' updates the whole series,
 * 'nachfolgende' splits it at the occurrence date (truncate + new slot),
 * 'einzeln' replaces one occurrence (exception + one-day slot). Multi-event
 * scopes run in one DB transaction; the EventStore joins it.
 */
final readonly class BookingService
{
    /** Assumed duration of a match incl. buffer, kickoff to end. */
    private const string MATCH_DURATION = '+2 hours';

    /** Upper bound for a slot's validity span (keeps expansion bounded). */
    private const int MAX_VALIDITY_DAYS = 400;

    private const array EDIT_SCOPES = ['alle', 'nachfolgende', 'einzeln'];

    public function __construct(
        private \PDO $pdo,
        private EventStore $eventStore,
        private TrainingSlotRepository $slots,
        private SlotExceptionRepository $exceptions,
        private PitchRestrictionRepository $restrictions,
        private MatchRepository $matches,
        private TeamRepository $teams,
        private PitchRepository $pitches,
    ) {
    }

    /**
     * Scope-aware dry run for the booking dialog (same effective payload as
     * the corresponding write).
     *
     * @param array<string, mixed> $input
     */
    public function check(array $input, ?int $slotId = null): ConflictCheckResult
    {
        $plan = $this->plan($input, $slotId);

        return $this->checkPayload($plan['payload'], $plan['ignore_slot_id'], $plan['extra_exceptions']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, warnings: list<string>}
     */
    public function createSlot(array $input, EventContext $context): array
    {
        $payload = $this->validate($input);

        $result = $this->checkPayload($payload, null, []);
        if ($result->hasConflicts()) {
            throw new ConflictException($result->conflicts);
        }

        $id = $this->eventStore
            ->append(AggregateType::TrainingSlot, null, EventType::Created, $payload, $context)
            ->aggregateId;

        return ['id' => $id, 'warnings' => $result->warnings];
    }

    /**
     * @param array<string, mixed> $input carries edit_scope + datum (+ datum_neu)
     * @return array{id: int, warnings: list<string>} id of the slot that now
     *         holds the edited occurrences (a new one for split/single edits)
     */
    public function updateSlot(int $id, array $input, EventContext $context): array
    {
        $slot = $this->slots->find($id);
        if ($slot === null) {
            throw new ValidationException(['id' => 'Belegung nicht gefunden.']);
        }

        $plan = $this->plan($input, $id);

        $result = $this->checkPayload($plan['payload'], $plan['ignore_slot_id'], $plan['extra_exceptions']);
        if ($result->hasConflicts()) {
            throw new ConflictException($result->conflicts);
        }

        $resultId = match ($plan['scope']) {
            'alle' => $this->applyUpdateAll($id, $plan['payload'], $context),
            'nachfolgende' => $this->applySplit($id, $slot, $plan['payload'], $context),
            'einzeln' => $this->applySingle($id, (string) $plan['datum'], $plan['payload'], $context),
            default => throw new ValidationException(['edit_scope' => 'Unbekannter Bearbeitungsumfang.']),
        };

        return ['id' => $resultId, 'warnings' => $result->warnings];
    }

    public function deleteSlot(int $id, EventContext $context): void
    {
        $slot = $this->slots->find($id);
        if ($slot === null) {
            throw new ValidationException(['id' => 'Belegung nicht gefunden.']);
        }

        $this->eventStore->append(
            AggregateType::TrainingSlot,
            $id,
            EventType::Deleted,
            self::rowPayload($slot),
            $context,
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addException(int $slotId, array $input, EventContext $context): int
    {
        $slot = $this->slots->find($slotId);
        if ($slot === null) {
            throw new ValidationException(['slot_id' => 'Belegung nicht gefunden.']);
        }

        $datum = $this->occurrenceDate($slot, (string) ($input['datum'] ?? ''));

        return $this->eventStore->append(AggregateType::SlotException, null, EventType::Created, [
            'slot_id' => $slotId,
            'datum' => $datum,
            'grund' => mb_substr(trim((string) ($input['grund'] ?? '')), 0, 255),
        ], $context)->aggregateId;
    }

    public function deleteException(int $id, EventContext $context): void
    {
        $exception = $this->exceptions->find($id);
        if ($exception === null) {
            throw new ValidationException(['id' => 'Ausfall nicht gefunden.']);
        }

        $this->eventStore->append(AggregateType::SlotException, $id, EventType::Deleted, [
            'slot_id' => (int) $exception['slot_id'],
            'datum' => (string) $exception['datum'],
            'grund' => (string) $exception['grund'],
        ], $context);
    }

    // ---- edit scopes ----

    /**
     * @param array<string, mixed> $payload
     */
    private function applyUpdateAll(int $id, array $payload, EventContext $context): int
    {
        $this->eventStore->append(AggregateType::TrainingSlot, $id, EventType::Updated, $payload, $context);

        return $id;
    }

    /**
     * 'nachfolgende': truncate the series before the occurrence date and
     * continue with a new slot. When no occurrence would remain before the
     * split (edit starts at the first one), the whole series is simply
     * updated instead of leaving an empty stub behind.
     *
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $payload validated, gueltig_ab = split date
     */
    private function applySplit(int $id, array $slot, array $payload, EventContext $context): int
    {
        $vortag = new \DateTimeImmutable((string) $payload['gueltig_ab'])->modify('-1 day')->format('Y-m-d');

        $remaining = SlotExpander::expand(
            [$slot],
            $this->exceptions->findForSlots([$id]),
            (string) $slot['gueltig_ab'],
            $vortag,
        );
        if ($remaining === []) {
            return $this->applyUpdateAll($id, $payload, $context);
        }

        $truncated = self::rowPayload($slot, ['gueltig_bis' => $vortag]);

        return $this->transactional(function () use ($id, $truncated, $payload, $context): int {
            $this->eventStore->append(AggregateType::TrainingSlot, $id, EventType::Updated, $truncated, $context);

            return $this->eventStore
                ->append(AggregateType::TrainingSlot, null, EventType::Created, $payload, $context)
                ->aggregateId;
        });
    }

    /**
     * 'einzeln': cancel the original occurrence via an exception and replace
     * it with a one-day slot (which may sit on another date).
     *
     * @param array<string, mixed> $payload validated one-day payload
     */
    private function applySingle(int $id, string $datum, array $payload, EventContext $context): int
    {
        return $this->transactional(function () use ($id, $datum, $payload, $context): int {
            $this->eventStore->append(AggregateType::SlotException, null, EventType::Created, [
                'slot_id' => $id,
                'datum' => $datum,
                'grund' => 'Termin einzeln geändert',
            ], $context);

            return $this->eventStore
                ->append(AggregateType::TrainingSlot, null, EventType::Created, $payload, $context)
                ->aggregateId;
        });
    }

    /**
     * Resolves input + scope into the payload to validate/check plus the
     * conflict-check context (shared by check() and updateSlot()).
     *
     * @param array<string, mixed> $input
     * @return array{
     *     scope: string,
     *     payload: array<string, mixed>,
     *     datum: ?string,
     *     ignore_slot_id: ?int,
     *     extra_exceptions: list<array{slot_id: int, datum: string}>,
     * }
     */
    private function plan(array $input, ?int $slotId): array
    {
        $scope = (string) ($input['edit_scope'] ?? 'alle');
        if ($slotId === null) {
            return [
                'scope' => 'alle',
                'payload' => $this->validate($input),
                'datum' => null,
                'ignore_slot_id' => null,
                'extra_exceptions' => [],
            ];
        }
        if (!in_array($scope, self::EDIT_SCOPES, true)) {
            throw new ValidationException(['edit_scope' => 'Unbekannter Bearbeitungsumfang.']);
        }

        $slot = $this->slots->find($slotId);
        if ($slot === null) {
            throw new ValidationException(['id' => 'Belegung nicht gefunden.']);
        }

        if ($scope === 'nachfolgende') {
            $datum = self::parseDate((string) ($input['datum'] ?? ''));
            if ($datum === null || $datum < (string) $slot['gueltig_ab'] || $datum > (string) $slot['gueltig_bis']) {
                throw new ValidationException(['datum' => 'Das Datum liegt außerhalb des Gültigkeitszeitraums.']);
            }

            return [
                'scope' => $scope,
                'payload' => $this->validate([...$input, 'gueltig_ab' => $datum]),
                'datum' => $datum,
                'ignore_slot_id' => $slotId,
                'extra_exceptions' => [],
            ];
        }

        if ($scope === 'einzeln') {
            $datum = $this->occurrenceDate($slot, (string) ($input['datum'] ?? ''));
            $datumNeu = self::parseDate((string) ($input['datum_neu'] ?? '')) ?? $datum;

            $payload = $this->validate([
                ...$input,
                'wochentage' => [(int) new \DateTimeImmutable($datumNeu)->format('N')],
                'gueltig_ab' => $datumNeu,
                'gueltig_bis' => $datumNeu,
            ]);

            return [
                'scope' => $scope,
                'payload' => $payload,
                'datum' => $datum,
                // the replaced occurrence is freed by its exception; every
                // other occurrence of the same slot stays a real conflict
                'ignore_slot_id' => null,
                'extra_exceptions' => [['slot_id' => $slotId, 'datum' => $datum]],
            ];
        }

        return [
            'scope' => 'alle',
            'payload' => $this->validate($input),
            'datum' => null,
            'ignore_slot_id' => $slotId,
            'extra_exceptions' => [],
        ];
    }

    /**
     * Validates that $datum is an actual occurrence of the slot (in range,
     * on one of its weekdays, not already cancelled).
     *
     * @param array<string, mixed> $slot
     */
    private function occurrenceDate(array $slot, string $input): string
    {
        $datum = self::parseDate($input);
        if ($datum === null) {
            throw new ValidationException(['datum' => 'Bitte ein gültiges Datum angeben.']);
        }
        if ($datum < (string) $slot['gueltig_ab'] || $datum > (string) $slot['gueltig_bis']) {
            throw new ValidationException(['datum' => 'Das Datum liegt außerhalb des Gültigkeitszeitraums.']);
        }
        $weekdays = array_map(intval(...), (array) json_decode((string) $slot['wochentage'], true));
        if (!in_array((int) new \DateTimeImmutable($datum)->format('N'), $weekdays, true)) {
            throw new ValidationException(['datum' => 'An diesem Datum findet die Belegung nicht statt.']);
        }
        if ($this->exceptions->existsForSlotAndDate((int) $slot['id'], $datum)) {
            throw new ValidationException(['datum' => 'Für dieses Datum ist bereits ein Ausfall eingetragen.']);
        }

        return $datum;
    }

    /**
     * @param array<string, mixed> $payload validated slot payload
     * @param list<array{slot_id: int, datum: string}> $extraExceptions
     */
    private function checkPayload(array $payload, ?int $ignoreSlotId, array $extraExceptions): ConflictCheckResult
    {
        $von = (string) $payload['gueltig_ab'];
        $bis = (string) $payload['gueltig_bis'];

        $candidate = [...$payload, 'id' => 0];
        $candidateOccurrences = SlotExpander::expand([$candidate], [], $von, $bis);

        // existing slots on the same pitch, minus their exceptions
        $otherSlots = $this->slots->findOverlapping($von, $bis, (int) $payload['pitch_id'], $ignoreSlotId);
        $otherOccurrences = SlotExpander::expand(
            $otherSlots,
            [
                ...$this->exceptions->findForSlots(array_map(static fn(array $s): int => (int) $s['id'], $otherSlots)),
                ...$extraExceptions,
            ],
            $von,
            $bis,
        );
        $occurrencesByDate = [];
        foreach ($otherOccurrences as $occurrence) {
            $occurrencesByDate[$occurrence->datum][] = $occurrence;
        }

        $matches = $this->matches->findInRange($von . ' 00:00:00', $bis . ' 23:59:59', (int) $payload['pitch_id']);
        $restrictions = $this->restrictions->findOverlapping($von . ' 00:00:00', $bis . ' 23:59:59', (int) $payload['pitch_id']);

        $teamNames = [];
        foreach ($this->teams->findAll() as $team) {
            $teamNames[(int) $team['id']] = (string) $team['name'];
        }
        $namesOf = static fn(array $teamIds): string => implode(' + ', array_map(
            static fn(int $teamId): string => $teamNames[$teamId] ?? ('Team #' . $teamId),
            $teamIds,
        ));

        $conflicts = [];
        $warnings = [];

        foreach ($candidateOccurrences as $occurrence) {
            foreach ($occurrencesByDate[$occurrence->datum] ?? [] as $other) {
                if (self::overlaps($occurrence->start, $occurrence->end, $other->start, $other->end)) {
                    $conflicts[] = sprintf(
                        'Kollidiert am %s mit der Belegung von %s (%s–%s Uhr).',
                        self::germanDate($occurrence->datum),
                        $namesOf($other->teamIds),
                        $other->start->format('H:i'),
                        $other->end->format('H:i'),
                    );
                }
            }

            foreach ($matches as $match) {
                if ((string) $match['status'] === 'abgesagt') {
                    continue;
                }
                $matchStart = new \DateTimeImmutable((string) $match['anstoss']);
                $matchEnd = $matchStart->modify(self::MATCH_DURATION);
                if (self::overlaps($occurrence->start, $occurrence->end, $matchStart, $matchEnd)) {
                    $conflicts[] = sprintf(
                        'Kollidiert am %s mit dem Spiel gegen %s (Anstoß %s Uhr).',
                        self::germanDate($occurrence->datum),
                        (string) $match['gegner'],
                        $matchStart->format('H:i'),
                    );
                }
            }

            foreach ($restrictions as $restriction) {
                $restrictionStart = new \DateTimeImmutable((string) $restriction['von']);
                $restrictionEnd = new \DateTimeImmutable((string) $restriction['bis']);
                if (!self::overlaps($occurrence->start, $occurrence->end, $restrictionStart, $restrictionEnd)) {
                    continue;
                }
                if ((string) $restriction['art'] === RestrictionArt::Gesperrt->value) {
                    $conflicts[] = sprintf(
                        'Platz ist am %s gesperrt: %s',
                        self::germanDate($occurrence->datum),
                        (string) $restriction['grund'],
                    );
                } else {
                    $warnings[] = sprintf(
                        'Platz ist am %s eingeschränkt nutzbar: %s',
                        self::germanDate($occurrence->datum),
                        (string) $restriction['grund'],
                    );
                }
            }
        }

        return new ConflictCheckResult(
            array_values(array_unique($conflicts)),
            array_values(array_unique($warnings)),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input): array
    {
        $errors = [];

        $teamIds = array_values(array_unique(array_map(intval(...), (array) ($input['team_ids'] ?? []))));
        if ($teamIds === []) {
            $errors['team_ids'] = 'Bitte mindestens ein Team wählen.';
        } else {
            foreach ($teamIds as $teamId) {
                if ($teamId <= 0 || $this->teams->find($teamId) === null) {
                    $errors['team_ids'] = 'Bitte nur vorhandene Teams wählen.';
                    break;
                }
            }
        }

        $pitchId = (int) ($input['pitch_id'] ?? 0);
        if ($pitchId <= 0 || $this->pitches->find($pitchId) === null) {
            $errors['pitch_id'] = 'Bitte einen vorhandenen Platz wählen.';
        }

        $wochentage = array_values(array_unique(array_map(intval(...), (array) ($input['wochentage'] ?? []))));
        sort($wochentage);
        if ($wochentage === []) {
            $errors['wochentage'] = 'Bitte mindestens einen Wochentag wählen.';
        } elseif ($wochentage[0] < 1 || $wochentage[count($wochentage) - 1] > 7) {
            $errors['wochentage'] = 'Bitte gültige Wochentage wählen.';
        }

        $beginn = self::parseTime((string) ($input['beginn'] ?? ''));
        $ende = self::parseTime((string) ($input['ende'] ?? ''));
        if ($beginn === null || $ende === null) {
            $errors['beginn'] = 'Bitte gültige Uhrzeiten angeben (HH:MM).';
        } elseif ($beginn >= $ende) {
            $errors['beginn'] = 'Beginn muss vor dem Ende liegen.';
        }

        $gueltigAb = self::parseDate((string) ($input['gueltig_ab'] ?? ''));
        $gueltigBis = self::parseDate((string) ($input['gueltig_bis'] ?? ''));
        if ($gueltigAb === null || $gueltigBis === null) {
            $errors['gueltig_ab'] = 'Bitte einen gültigen Zeitraum angeben.';
        } elseif ($gueltigAb > $gueltigBis) {
            $errors['gueltig_ab'] = '„Gültig ab" muss vor „gültig bis" liegen.';
        } elseif (new \DateTimeImmutable($gueltigAb)->diff(new \DateTimeImmutable($gueltigBis))->days > self::MAX_VALIDITY_DAYS) {
            $errors['gueltig_bis'] = sprintf('Der Gültigkeitszeitraum darf höchstens %d Tage umfassen.', self::MAX_VALIDITY_DAYS);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'team_ids' => $teamIds,
            'wochentage' => $wochentage,
            'pitch_id' => $pitchId,
            'beginn' => $beginn,
            'ende' => $ende,
            'gueltig_ab' => $gueltigAb,
            'gueltig_bis' => $gueltigBis,
        ];
    }

    /**
     * Full picture of a projection row as event payload.
     *
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function rowPayload(array $slot, array $overrides = []): array
    {
        return [
            'team_ids' => array_map(intval(...), (array) json_decode((string) $slot['team_ids'], true)),
            'wochentage' => array_map(intval(...), (array) json_decode((string) $slot['wochentage'], true)),
            'pitch_id' => (int) $slot['pitch_id'],
            'beginn' => (string) $slot['beginn'],
            'ende' => (string) $slot['ende'],
            'gueltig_ab' => (string) $slot['gueltig_ab'],
            'gueltig_bis' => (string) $slot['gueltig_bis'],
            ...$overrides,
        ];
    }

    /**
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function transactional(\Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function overlaps(
        \DateTimeImmutable $startA,
        \DateTimeImmutable $endA,
        \DateTimeImmutable $startB,
        \DateTimeImmutable $endB,
    ): bool {
        return $startA < $endB && $startB < $endA;
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

    private static function germanDate(string $isoDate): string
    {
        return new \DateTimeImmutable($isoDate)->format('d.m.Y');
    }
}
