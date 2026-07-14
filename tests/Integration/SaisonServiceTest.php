<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\TrainingSlotRepository;
use App\Service\Saison\SaisonService;
use App\Tests\Support\DatabaseTestCase;

final class SaisonServiceTest extends DatabaseTestCase
{
    public function testCopySlotsKeepsTeamsAndWeekdays(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId);
        $team1 = $this->createTeam('E1');
        $team2 = $this->createTeam('E2');

        $created = $this->bookingService()->createSlot([
            'team_ids' => [$team1, $team2],
            'pitch_id' => $pitchId,
            'wochentage' => [2, 4],
            'beginn' => '19:00',
            'ende' => '20:30',
            'gueltig_ab' => '2025-08-01',
            'gueltig_bis' => '2026-06-30',
        ], $this->context());

        $saison = new SaisonService(
            $this->pdo(),
            new TrainingSlotRepository($this->pdo()),
            $this->bookingService(),
        );

        $candidates = $saison->copyCandidates();
        self::assertCount(1, $candidates);
        self::assertSame('E1 + E2', $candidates[0]['team_names']);
        self::assertSame([2, 4], $candidates[0]['wochentage_list']);

        $result = $saison->copySlots([$created['id']], '2026-08-01', '2027-06-30', $this->context());

        self::assertSame(1, $result['angelegt']);
        self::assertSame([], $result['fehler']);
        $slots = $this->dumpTable('training_slot');
        self::assertCount(2, $slots);
        self::assertSame([$team1, $team2], json_decode((string) $slots[1]['team_ids'], true));
        self::assertSame([2, 4], json_decode((string) $slots[1]['wochentage'], true));
        self::assertSame('2026-08-01', $slots[1]['gueltig_ab']);
    }
}
