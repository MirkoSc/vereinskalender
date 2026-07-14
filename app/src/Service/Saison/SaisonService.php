<?php

declare(strict_types=1);

namespace App\Service\Saison;

use App\Domain\EventContext;
use App\Repository\TrainingSlotRepository;
use App\Service\Kalender\BookingService;
use App\Service\Kalender\ConflictException;
use App\Service\ValidationException;

/**
 * Season assistant, part 3 (CLAUDE.md section 6): copy last season's
 * training slots into a new validity range. Teams/import URLs are managed
 * through their regular admin pages, the assistant links there.
 */
final readonly class SaisonService
{
    public function __construct(
        private \PDO $pdo,
        private TrainingSlotRepository $slots,
        private BookingService $booking,
    ) {
    }

    /**
     * Slots grouped for the copy list; expired slots first.
     *
     * @return list<array<string, mixed>>
     */
    public function copyCandidates(): array
    {
        // today boundary from PHP (Europe/Berlin), not CURDATE() (UTC session)
        $stmt = $this->pdo->prepare(
            'SELECT s.*, p.name AS pitch_name, (s.gueltig_bis < ?) AS abgelaufen
             FROM training_slot s
             LEFT JOIN pitch p ON p.id = s.pitch_id',
        );
        $stmt->execute([new \DateTimeImmutable('today')->format('Y-m-d')]);
        $rows = $stmt->fetchAll();

        $teamNames = [];
        foreach ($this->pdo->query('SELECT id, name FROM team')->fetchAll() as $team) {
            $teamNames[(int) $team['id']] = (string) $team['name'];
        }

        foreach ($rows as &$row) {
            $row['team_ids_list'] = array_map(intval(...), (array) json_decode((string) $row['team_ids'], true));
            $row['wochentage_list'] = array_map(intval(...), (array) json_decode((string) $row['wochentage'], true));
            $row['team_names'] = implode(' + ', array_map(
                static fn(int $teamId): string => $teamNames[$teamId] ?? ('Team #' . $teamId),
                $row['team_ids_list'],
            ));
        }
        unset($row);

        usort($rows, static fn(array $a, array $b): int => [
            -(int) $a['abgelaufen'], (string) $a['team_names'], $a['wochentage_list'][0] ?? 0, (string) $a['beginn'],
        ] <=> [
            -(int) $b['abgelaufen'], (string) $b['team_names'], $b['wochentage_list'][0] ?? 0, (string) $b['beginn'],
        ]);

        return $rows;
    }

    /**
     * Copies the selected slots into the new validity range (as events,
     * conflict-checked one by one).
     *
     * @param list<int> $slotIds
     * @return array{angelegt: int, fehler: list<string>}
     */
    public function copySlots(array $slotIds, string $gueltigAb, string $gueltigBis, EventContext $context): array
    {
        $angelegt = 0;
        $fehler = [];

        foreach ($slotIds as $slotId) {
            $slot = $this->slots->find($slotId);
            if ($slot === null) {
                $fehler[] = sprintf('Slot #%d nicht gefunden.', $slotId);
                continue;
            }

            try {
                $this->booking->createSlot([
                    'team_ids' => (array) json_decode((string) $slot['team_ids'], true),
                    'pitch_id' => (int) $slot['pitch_id'],
                    'wochentage' => (array) json_decode((string) $slot['wochentage'], true),
                    'beginn' => substr((string) $slot['beginn'], 0, 5),
                    'ende' => substr((string) $slot['ende'], 0, 5),
                    'gueltig_ab' => $gueltigAb,
                    'gueltig_bis' => $gueltigBis,
                ], $context);
                $angelegt++;
            } catch (ConflictException $e) {
                $fehler[] = sprintf('Slot #%d: %s', $slotId, implode(' ', array_slice($e->getConflicts(), 0, 2)));
            } catch (ValidationException $e) {
                $fehler[] = sprintf('Slot #%d: %s', $slotId, implode(' ', $e->getErrors()));
            }
        }

        return ['angelegt' => $angelegt, 'fehler' => $fehler];
    }
}
