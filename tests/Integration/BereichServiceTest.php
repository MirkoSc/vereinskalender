<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\BereichRepository;
use App\Repository\TeamRepository;
use App\Service\Stammdaten\BereichService;
use App\Service\Stammdaten\TeamService;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #27: bereiche become a managed aggregate (name, kuerzel, sortierung,
 * aktiv) instead of the fixed enum - CRUD works like venues/pitches/teams,
 * with the same delete-guard-while-referenced / deactivate-instead pattern
 * as VenueService (pitches) and TeamService (home pitch rules).
 */
final class BereichServiceTest extends DatabaseTestCase
{
    private function bereichService(): BereichService
    {
        return new BereichService($this->eventStore(), new BereichRepository($this->pdo()));
    }

    private function teamService(): TeamService
    {
        return new TeamService($this->eventStore(), new TeamRepository($this->pdo()), new BereichRepository($this->pdo()));
    }

    public function testCreatePersistsAsEventAndProjection(): void
    {
        $id = $this->bereichService()->create([
            'name' => 'A-Jugend',
            'kuerzel' => 'A',
            'sortierung' => 70,
            'aktiv' => '1',
        ], $this->context());

        $bereich = new BereichRepository($this->pdo())->find($id);
        self::assertNotNull($bereich);
        self::assertSame('A-Jugend', $bereich['name']);
        self::assertSame('A', $bereich['kuerzel']);
        self::assertSame(1, (int) $bereich['aktiv']);

        $event = $this->pdo()
            ->query('SELECT * FROM event WHERE aggregat_typ = "bereich" AND event_typ = "created" ORDER BY id DESC LIMIT 1')
            ->fetch();
        self::assertSame('created', $event['event_typ']);
    }

    public function testCreateWithoutNameIsRejected(): void
    {
        try {
            $this->bereichService()->create(['name' => '', 'kuerzel' => 'A'], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->getErrors());
        }
    }

    public function testCreateWithoutKuerzelIsRejected(): void
    {
        try {
            $this->bereichService()->create(['name' => 'A-Jugend', 'kuerzel' => ''], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('kuerzel', $e->getErrors());
        }
    }

    public function testUpdateRenamesBereich(): void
    {
        $id = $this->createBereich('A-Jugend', 'A');

        $this->bereichService()->update($id, [
            'name' => 'A-Jugend (neu)',
            'kuerzel' => 'A',
            'sortierung' => 70,
            'aktiv' => '1',
        ], $this->context());

        $bereich = new BereichRepository($this->pdo())->find($id);
        self::assertSame('A-Jugend (neu)', $bereich['name']);
    }

    public function testDeleteIsRejectedWhileTeamsReferenceTheBereich(): void
    {
        $bereichId = $this->createBereich('A-Jugend', 'A');
        $this->teamService()->create([
            'bereich_id' => (string) $bereichId,
            'name' => 'A1',
            'kuerzel' => 'A1',
            'farbe' => '#0969da',
            'aktiv' => '1',
        ], $this->context());

        try {
            $this->bereichService()->delete($bereichId, $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }

        self::assertNotNull(new BereichRepository($this->pdo())->find($bereichId), 'bereich must still exist');
    }

    public function testDeleteSucceedsWithoutReferencingTeams(): void
    {
        $bereichId = $this->createBereich('Freizeit', 'FR');

        $this->bereichService()->delete($bereichId, $this->context());

        self::assertNull(new BereichRepository($this->pdo())->find($bereichId));
    }

    public function testDeactivatingHidesBereichFromAktiveButKeepsItFindable(): void
    {
        $bereichId = $this->createBereich('A-Jugend', 'A');

        $this->bereichService()->update($bereichId, [
            'name' => 'A-Jugend',
            'kuerzel' => 'A',
            'sortierung' => 70,
            'aktiv' => '',
        ], $this->context());

        $repo = new BereichRepository($this->pdo());
        self::assertNotNull($repo->find($bereichId));
        self::assertFalse(in_array($bereichId, array_column($repo->findAktive(), 'id'), true));
    }

    /**
     * A team keeps its (now deactivated) bereich on update - only NEW
     * assignments to an inactive bereich are blocked (mirrors team.aktiv).
     */
    public function testTeamKeepsItsOwnDeactivatedBereichOnUpdate(): void
    {
        $bereichId = $this->createBereich('A-Jugend', 'A');
        $teamService = $this->teamService();
        $teamId = $teamService->create([
            'bereich_id' => (string) $bereichId,
            'name' => 'A1',
            'kuerzel' => 'A1',
            'farbe' => '#0969da',
            'aktiv' => '1',
        ], $this->context());

        $this->bereichService()->update($bereichId, [
            'name' => 'A-Jugend',
            'kuerzel' => 'A',
            'sortierung' => 70,
            'aktiv' => '',
        ], $this->context());

        // still allowed: the team keeps its own (now inactive) bereich
        $teamService->update($teamId, [
            'bereich_id' => (string) $bereichId,
            'name' => 'A1 (SG)',
            'kuerzel' => 'A1',
            'farbe' => '#0969da',
            'aktiv' => '1',
        ], $this->context());

        $team = new TeamRepository($this->pdo())->find($teamId);
        self::assertSame('A1 (SG)', $team['name']);

        // but a DIFFERENT team may not newly pick the deactivated bereich
        try {
            $teamService->create([
                'bereich_id' => (string) $bereichId,
                'name' => 'A2',
                'kuerzel' => 'A2',
                'farbe' => '#0969da',
                'aktiv' => '1',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('bereich_id', $e->getErrors());
        }
    }
}
