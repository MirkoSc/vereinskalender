<?php

declare(strict_types=1);

namespace App\Service\Kalender;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Domain\VermietungArt;
use App\Repository\SportheimRaumRepository;
use App\Repository\SportheimRepository;
use App\Repository\VermietungRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Public write path (Ebene 2, CLAUDE.md section 6) for Vermietungen (Issue
 * #36): anyone with an editor_name may create/edit/delete a Sportheim
 * rental. Deliberately runs NO conflict check - a Vermietung never blocks
 * or warns (that's enforced the other way round, in BookingService, which
 * treats an existing Vermietung as a pure hint for slots/matches).
 */
final readonly class VermietungService
{
    public function __construct(
        private EventStore $eventStore,
        private VermietungRepository $vermietungen,
        private SportheimRepository $sportheime,
        private SportheimRaumRepository $raeume,
    ) {
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function create(array $input, EventContext $context): int
    {
        $payload = $this->validate($input);

        return $this->eventStore
            ->append(AggregateType::Vermietung, null, EventType::Created, $payload, $context)
            ->aggregateId;
    }

    /**
     * @param array<string, mixed> $input raw form input
     */
    public function update(int $id, array $input, EventContext $context): void
    {
        if ($this->vermietungen->find($id) === null) {
            throw new ValidationException(['id' => 'Vermietung nicht gefunden.']);
        }

        $payload = $this->validate($input);
        $this->eventStore->append(AggregateType::Vermietung, $id, EventType::Updated, $payload, $context);
    }

    public function delete(int $id, EventContext $context): void
    {
        $vermietung = $this->vermietungen->find($id);
        if ($vermietung === null) {
            throw new ValidationException(['id' => 'Vermietung nicht gefunden.']);
        }

        $this->eventStore->append(AggregateType::Vermietung, $id, EventType::Deleted, [
            'sportheim_id' => (int) $vermietung['sportheim_id'],
            'art' => VermietungArt::fromPayload($vermietung['art'] ?? null)->value,
            'raum_ids' => array_map(intval(...), (array) json_decode((string) $vermietung['raum_ids'], true)),
            'von' => (string) $vermietung['von'],
            'bis' => (string) $vermietung['bis'],
            'titel' => (string) $vermietung['titel'],
            'kontakt' => $vermietung['kontakt'] !== null ? (string) $vermietung['kontakt'] : null,
            'bemerkung' => $vermietung['bemerkung'] !== null ? (string) $vermietung['bemerkung'] : null,
        ], $context);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> validated full picture payload
     */
    private function validate(array $input): array
    {
        $errors = [];

        $sportheimId = (int) ($input['sportheim_id'] ?? 0);
        $sportheim = $sportheimId > 0 ? $this->sportheime->find($sportheimId) : null;
        if ($sportheim === null || (int) $sportheim['aktiv'] !== 1) {
            $errors['sportheim_id'] = 'Bitte ein vorhandenes, aktives Sportheim wählen.';
        }

        // Issue #63: a missing art stays valid and means 'vermietung' (older
        // clients keep writing); only an unknown value is rejected.
        $artInput = trim((string) ($input['art'] ?? ''));
        $art = $artInput === '' ? VermietungArt::Vermietung : VermietungArt::tryFrom($artInput);
        if ($art === null) {
            $errors['art'] = 'Bitte eine gültige Art wählen.';
        }

        $raumIds = array_values(array_unique(array_map(intval(...), (array) ($input['raum_ids'] ?? []))));
        if ($sportheim !== null) {
            foreach ($raumIds as $raumId) {
                $raum = $this->raeume->find($raumId);
                if ($raum === null || (int) $raum['sportheim_id'] !== $sportheimId) {
                    $errors['raum_ids'] = 'Bitte nur Räume dieses Sportheims wählen.';
                    break;
                }
            }
        }

        $von = self::parseDateTime((string) ($input['von'] ?? ''));
        $bis = self::parseDateTime((string) ($input['bis'] ?? ''));
        if ($von === null || $bis === null) {
            $errors['von'] = 'Bitte einen gültigen Zeitraum angeben.';
        } elseif ($von >= $bis) {
            $errors['von'] = 'Beginn muss vor dem Ende liegen.';
        }

        $titel = trim((string) ($input['titel'] ?? ''));
        if ($titel === '' || mb_strlen($titel) > 255) {
            $errors['titel'] = 'Anlass ist erforderlich (max. 255 Zeichen).';
        }

        $kontakt = trim((string) ($input['kontakt'] ?? ''));
        if (mb_strlen($kontakt) > 255) {
            $errors['kontakt'] = 'Kontakt darf max. 255 Zeichen lang sein.';
        }

        $bemerkung = trim((string) ($input['bemerkung'] ?? ''));

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return [
            'sportheim_id' => $sportheimId,
            'art' => $art->value,
            'raum_ids' => $raumIds,
            'von' => $von,
            'bis' => $bis,
            'titel' => $titel,
            'kontakt' => $kontakt === '' ? null : $kontakt,
            'bemerkung' => $bemerkung === '' ? null : $bemerkung,
        ];
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
