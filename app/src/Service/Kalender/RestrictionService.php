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

final readonly class RestrictionService
{
    public function __construct(
        private EventStore $eventStore,
        private PitchRestrictionRepository $restrictions,
        private PitchRepository $pitches,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, EventContext $context): int
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

        return $this->eventStore->append(AggregateType::PitchRestriction, null, EventType::Created, [
            'pitch_id' => $pitchId,
            'von' => $von,
            'bis' => $bis,
            'art' => $art->value,
            'grund' => $grund,
        ], $context)->aggregateId;
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
