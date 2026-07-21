<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Domain\RestrictionArt;
use App\Repository\PitchRepository;
use App\Repository\PitchRestrictionRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Public write path (Ebene 2, CLAUDE.md section 5/6) for pitch_restriction,
 * exactly like manual matches (MatchService) and Vermietungen
 * (VermietungService): create/update/delete as events, delete = delete
 * event. Unlike those, a restriction is itself an input to the conflict
 * check (BookingService reads pitch_restriction live) - tightening the art
 * therefore takes effect on the very next check without any extra wiring.
 * It deliberately does NOT invalidate bookings that already exist within
 * its range (Issue #64); occurrencesOnPitch() surfaces them as a hint
 * instead, on both create and update.
 */
final readonly class RestrictionService
{
    public function __construct(
        private EventStore $eventStore,
        private PitchRestrictionRepository $restrictions,
        private PitchRepository $pitches,
        private BookingService $booking,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, betroffene: list<string>}
     */
    public function create(array $input, EventContext $context): array
    {
        $payload = $this->validate($input);

        $id = $this->eventStore
            ->append(AggregateType::PitchRestriction, null, EventType::Created, $payload, $context)
            ->aggregateId;

        return ['id' => $id, 'betroffene' => $this->betroffeneTermine($payload)];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{betroffene: list<string>}
     */
    public function update(int $id, array $input, EventContext $context): array
    {
        if ($this->restrictions->find($id) === null) {
            throw new ValidationException(['id' => 'Einschränkung nicht gefunden.']);
        }

        $payload = $this->validate($input);
        $this->eventStore->append(AggregateType::PitchRestriction, $id, EventType::Updated, $payload, $context);

        return ['betroffene' => $this->betroffeneTermine($payload)];
    }

    public function delete(int $id, EventContext $context): void
    {
        $restriction = $this->restrictions->find($id);
        if ($restriction === null) {
            throw new ValidationException(['id' => 'Einschränkung nicht gefunden.']);
        }

        $this->eventStore->append(AggregateType::PitchRestriction, $id, EventType::Deleted, [
            'pitch_id' => (int) $restriction['pitch_id'],
            'von' => (string) $restriction['von'],
            'bis' => (string) $restriction['bis'],
            'art' => (string) $restriction['art'],
            'grund' => (string) $restriction['grund'],
        ], $context);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input): array
    {
        $errors = [];

        $pitchId = (int) ($input['pitch_id'] ?? 0);
        if ($pitchId <= 0 || $this->pitches->find($pitchId) === null) {
            $errors['pitch_id'] = 'Bitte einen vorhandenen Platz wählen.';
        }

        $art = RestrictionArt::tryFrom(trim((string) ($input['art'] ?? '')));
        if ($art === null) {
            $errors['art'] = 'Bitte die Art der Einschränkung wählen.';
        }

        $grund = trim((string) ($input['grund'] ?? ''));
        if ($grund === '' || mb_strlen($grund) > 255) {
            $errors['grund'] = 'Ein Grund ist erforderlich (max. 255 Zeichen).';
        }

        $von = self::parseDateTime((string) ($input['von'] ?? ''));
        $bis = self::parseDateTime((string) ($input['bis'] ?? ''));
        if ($von === null || $bis === null) {
            $errors['von'] = 'Bitte einen gültigen Zeitraum angeben.';
        } elseif ($von >= $bis) {
            $errors['von'] = 'Beginn muss vor dem Ende liegen.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'pitch_id' => $pitchId,
            'von' => $von,
            'bis' => $bis,
            'art' => $art->value,
            'grund' => $grund,
        ];
    }

    /**
     * German one-liners for existing bookings the just-written restriction
     * overlaps (Issue #64) - informational only, the write above already
     * happened regardless of what this returns.
     *
     * @param array<string, mixed> $payload validated restriction payload
     * @return list<string>
     */
    private function betroffeneTermine(array $payload): array
    {
        return array_map(
            static fn(Conflict $c): string => $c->nachricht,
            $this->booking->occurrencesOnPitch((int) $payload['pitch_id'], (string) $payload['von'], (string) $payload['bis']),
        );
    }

    private static function parseDateTime(string $value): ?string
    {
        $value = str_replace('T', ' ', trim($value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} ([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value)->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
