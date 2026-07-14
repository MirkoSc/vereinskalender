<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Domain\Occurrence;
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
 */
final readonly class BookingService
{
    /** Assumed duration of a match incl. buffer, kickoff to end. */
    private const string MATCH_DURATION = '+2 hours';

    /** Upper bound for a slot's validity span (keeps expansion bounded). */
    private const int MAX_VALIDITY_DAYS = 400;

    public function __construct(
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
     * @param array<string, mixed> $input
     */
    public function check(array $input, ?int $ignoreSlotId = null): ConflictCheckResult
    {
        return $this->checkPayload($this->validate($input), $ignoreSlotId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, warnings: list<string>}
     */
    public function createSlot(array $input, EventContext $context): array
    {
        $payload = $this->validate($input);

        $result = $this->checkPayload($payload, null);
        if ($result->hasConflicts()) {
            throw new ConflictException($result->conflicts);
        }

        $id = $this->eventStore
            ->append(AggregateType::TrainingSlot, null, EventType::Created, $payload, $context)
            ->aggregateId;

        return ['id' => $id, 'warnings' => $result->warnings];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, warnings: list<string>}
     */
    public function updateSlot(int $id, array $input, EventContext $context): array
    {
        if ($this->slots->find($id) === null) {
            throw new ValidationException(['id' => 'Belegung nicht gefunden.']);
        }

        $payload = $this->validate($input);

        $result = $this->checkPayload($payload, $id);
        if ($result->hasConflicts()) {
            throw new ConflictException($result->conflicts);
        }

        $this->eventStore->append(AggregateType::TrainingSlot, $id, EventType::Updated, $payload, $context);

        return ['id' => $id, 'warnings' => $result->warnings];
    }

    public function deleteSlot(int $id, EventContext $context): void
    {
        $slot = $this->slots->find($id);
        if ($slot === null) {
            throw new ValidationException(['id' => 'Belegung nicht gefunden.']);
        }

        $this->eventStore->append(AggregateType::TrainingSlot, $id, EventType::Deleted, [
            'team_id' => (int) $slot['team_id'],
            'pitch_id' => (int) $slot['pitch_id'],
            'wochentag' => (int) $slot['wochentag'],
            'beginn' => (string) $slot['beginn'],
            'ende' => (string) $slot['ende'],
            'gueltig_ab' => (string) $slot['gueltig_ab'],
            'gueltig_bis' => (string) $slot['gueltig_bis'],
        ], $context);
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

        $datum = self::parseDate((string) ($input['datum'] ?? ''));
        if ($datum === null) {
            throw new ValidationException(['datum' => 'Bitte ein gültiges Datum angeben.']);
        }
        if ($datum < (string) $slot['gueltig_ab'] || $datum > (string) $slot['gueltig_bis']) {
            throw new ValidationException(['datum' => 'Das Datum liegt außerhalb des Gültigkeitszeitraums.']);
        }
        if ((int) new \DateTimeImmutable($datum)->format('N') !== (int) $slot['wochentag']) {
            throw new ValidationException(['datum' => 'An diesem Datum findet die Belegung nicht statt.']);
        }
        if ($this->exceptions->existsForSlotAndDate($slotId, $datum)) {
            throw new ValidationException(['datum' => 'Für dieses Datum ist bereits ein Ausfall eingetragen.']);
        }

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

    /**
     * @param array<string, mixed> $payload validated slot payload
     */
    private function checkPayload(array $payload, ?int $ignoreSlotId): ConflictCheckResult
    {
        $von = (string) $payload['gueltig_ab'];
        $bis = (string) $payload['gueltig_bis'];

        $candidate = [...$payload, 'id' => 0];
        $candidateOccurrences = SlotExpander::expand([$candidate], [], $von, $bis);

        // existing slots on the same pitch, minus their exceptions
        $otherSlots = $this->slots->findOverlapping($von, $bis, (int) $payload['pitch_id'], $ignoreSlotId);
        $otherOccurrences = SlotExpander::expand(
            $otherSlots,
            $this->exceptions->findForSlots(array_map(static fn(array $s): int => (int) $s['id'], $otherSlots)),
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

        $conflicts = [];
        $warnings = [];

        foreach ($candidateOccurrences as $occurrence) {
            foreach ($occurrencesByDate[$occurrence->datum] ?? [] as $other) {
                if (self::overlaps($occurrence->start, $occurrence->end, $other->start, $other->end)) {
                    $conflicts[] = sprintf(
                        'Kollidiert am %s mit der Belegung von %s (%s–%s Uhr).',
                        self::germanDate($occurrence->datum),
                        $teamNames[$other->teamId] ?? ('Team #' . $other->teamId),
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

        $teamId = (int) ($input['team_id'] ?? 0);
        if ($teamId <= 0 || $this->teams->find($teamId) === null) {
            $errors['team_id'] = 'Bitte ein vorhandenes Team wählen.';
        }

        $pitchId = (int) ($input['pitch_id'] ?? 0);
        if ($pitchId <= 0 || $this->pitches->find($pitchId) === null) {
            $errors['pitch_id'] = 'Bitte einen vorhandenen Platz wählen.';
        }

        $wochentag = (int) ($input['wochentag'] ?? 0);
        if ($wochentag < 1 || $wochentag > 7) {
            $errors['wochentag'] = 'Bitte einen Wochentag wählen.';
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
            'team_id' => $teamId,
            'pitch_id' => $pitchId,
            'wochentag' => $wochentag,
            'beginn' => $beginn,
            'ende' => $ende,
            'gueltig_ab' => $gueltigAb,
            'gueltig_bis' => $gueltigBis,
        ];
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
