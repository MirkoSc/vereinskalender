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
        return $this->pdo
            ->query(
                'SELECT s.*, t.name AS team_name, p.name AS pitch_name,
                        (s.gueltig_bis < CURDATE()) AS abgelaufen
                 FROM training_slot s
                 LEFT JOIN team t ON t.id = s.team_id
                 LEFT JOIN pitch p ON p.id = s.pitch_id
                 ORDER BY abgelaufen DESC, t.sortierung, t.name, s.wochentag, s.beginn',
            )
            ->fetchAll();
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
                    'team_id' => (int) $slot['team_id'],
                    'pitch_id' => (int) $slot['pitch_id'],
                    'wochentag' => (int) $slot['wochentag'],
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
