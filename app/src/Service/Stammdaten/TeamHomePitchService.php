<?php

declare(strict_types=1);

namespace App\Service\Stammdaten;

use App\Domain\AggregateType;
use App\Domain\EventContext;
use App\Domain\EventType;
use App\Repository\PitchRepository;
use App\Repository\TeamHomePitchRepository;
use App\Repository\TeamRepository;
use App\Service\EventStore\EventStore;
use App\Service\ValidationException;

/**
 * Seasonal home pitch rules per team (CLAUDE.md section 3): gueltig_ab and
 * gueltig_bis are both inclusive, so a team's rules must never overlap, not
 * even on a shared boundary day.
 */
final readonly class TeamHomePitchService
{
    /** Upper bound for a rule's validity span (mirrors BookingService::MAX_VALIDITY_DAYS). */
    private const int MAX_VALIDITY_DAYS = 400;

    public function __construct(
        private EventStore $eventStore,
        private TeamHomePitchRepository $rules,
        private TeamRepository $teams,
        private PitchRepository $pitches,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, EventContext $context): int
    {
        $errors = [];

        $teamId = (int) ($input['team_id'] ?? 0);
        if ($teamId <= 0 || $this->teams->find($teamId) === null) {
            $errors['team_id'] = 'Team nicht gefunden.';
        }

        $pitchId = (int) ($input['pitch_id'] ?? 0);
        if ($pitchId <= 0 || $this->pitches->find($pitchId) === null) {
            $errors['pitch_id'] = 'Bitte einen vorhandenen Platz wählen.';
        }

        $gueltigAb = self::parseDate((string) ($input['gueltig_ab'] ?? ''));
        $gueltigBis = self::parseDate((string) ($input['gueltig_bis'] ?? ''));
        if ($gueltigAb === null || $gueltigBis === null) {
            $errors['gueltig_ab'] = 'Bitte einen gültigen Zeitraum angeben.';
        } elseif ($gueltigAb > $gueltigBis) {
            $errors['gueltig_ab'] = '„Gültig ab" muss vor oder gleich „gültig bis" liegen.';
        } elseif (new \DateTimeImmutable($gueltigAb)->diff(new \DateTimeImmutable($gueltigBis))->days > self::MAX_VALIDITY_DAYS) {
            $errors['gueltig_bis'] = sprintf('Der Gültigkeitszeitraum darf höchstens %d Tage umfassen.', self::MAX_VALIDITY_DAYS);
        } elseif ($teamId > 0) {
            $overlapping = $this->rules->findOverlapping($teamId, $gueltigAb, $gueltigBis);
            if ($overlapping !== []) {
                $other = $overlapping[0];
                $pitch = $this->pitches->find((int) $other['pitch_id']);
                $errors['gueltig_ab'] = sprintf(
                    'Zeitraum überschneidet sich mit der Regel „%s" (%s bis %s).',
                    $pitch !== null ? (string) $pitch['name'] : 'unbekannter Platz',
                    self::germanDate((string) $other['gueltig_ab']),
                    self::germanDate((string) $other['gueltig_bis']),
                );
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->eventStore->append(AggregateType::TeamHomePitch, null, EventType::Created, [
            'team_id' => $teamId,
            'pitch_id' => $pitchId,
            'gueltig_ab' => $gueltigAb,
            'gueltig_bis' => $gueltigBis,
        ], $context)->aggregateId;
    }

    public function delete(int $id, EventContext $context): void
    {
        $rule = $this->rules->find($id);
        if ($rule === null) {
            throw new ValidationException(['id' => 'Regel nicht gefunden.']);
        }

        $this->eventStore->append(AggregateType::TeamHomePitch, $id, EventType::Deleted, [
            'team_id' => (int) $rule['team_id'],
            'pitch_id' => (int) $rule['pitch_id'],
            'gueltig_ab' => (string) $rule['gueltig_ab'],
            'gueltig_bis' => (string) $rule['gueltig_bis'],
        ], $context);
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
