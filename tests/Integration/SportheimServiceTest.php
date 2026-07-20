<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\SportheimRaumRepository;
use App\Repository\SportheimRepository;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #36: Sportheim + sportheim_raum CRUD, mirroring the delete-guard-
 * while-referenced / deactivate-instead pattern of VenueService (pitches)
 * and BereichService (teams). A Sportheim is guarded by rooms, pitches, AND
 * Vermietungen; a room is guarded by Vermietungen referencing it.
 */
final class SportheimServiceTest extends DatabaseTestCase
{

    public function testCreatePersistsAsEventAndProjection(): void
    {
        $venueId = $this->createVenue();

        $id = $this->sportheimService()->create([
            'venue_id' => (string) $venueId,
            'name' => 'Sportheim Musterstadt',
            'adresse' => '',
            'sortierung' => 0,
            'aktiv' => '1',
        ], $this->context());

        $sportheim = new SportheimRepository($this->pdo())->find($id);
        self::assertNotNull($sportheim);
        self::assertSame('Sportheim Musterstadt', $sportheim['name']);
        self::assertSame($venueId, (int) $sportheim['venue_id']);
        self::assertNull($sportheim['adresse']);
    }

    public function testCreateWithoutVenueIsRejected(): void
    {
        try {
            $this->sportheimService()->create(['venue_id' => '', 'name' => 'Sportheim'], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('venue_id', $e->getErrors());
        }
    }

    public function testCreateWithoutNameIsRejected(): void
    {
        $venueId = $this->createVenue();

        try {
            $this->sportheimService()->create(['venue_id' => (string) $venueId, 'name' => ''], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->getErrors());
        }
    }

    public function testUpdateRenamesSportheim(): void
    {
        $venueId = $this->createVenue();
        $id = $this->createSportheim($venueId, 'Altes Sportheim');

        $this->sportheimService()->update($id, [
            'venue_id' => (string) $venueId,
            'name' => 'Neues Sportheim',
            'sortierung' => 0,
            'aktiv' => '1',
        ], $this->context());

        self::assertSame('Neues Sportheim', new SportheimRepository($this->pdo())->find($id)['name']);
    }

    public function testDeleteIsRejectedWhilePitchReferencesTheSportheim(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $this->createPitch($venueId, 'Platz', '#0969da', 'P1', $sportheimId);

        try {
            $this->sportheimService()->delete($sportheimId, $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }

        self::assertNotNull(new SportheimRepository($this->pdo())->find($sportheimId));
    }

    public function testDeleteIsRejectedWhileRoomsExist(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $this->createSportheimRaum($sportheimId);

        try {
            $this->sportheimService()->delete($sportheimId, $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testDeleteIsRejectedWhileVermietungReferencesTheSportheim(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $this->createVermietung($sportheimId, '2026-08-01 10:00:00', '2026-08-01 12:00:00');

        try {
            $this->sportheimService()->delete($sportheimId, $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }
    }

    public function testDeleteSucceedsWithoutReferences(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);

        $this->sportheimService()->delete($sportheimId, $this->context());

        self::assertNull(new SportheimRepository($this->pdo())->find($sportheimId));
    }

    public function testDeactivatingHidesSportheimFromAktiveButKeepsItFindable(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);

        $this->sportheimService()->update($sportheimId, [
            'venue_id' => (string) $venueId,
            'name' => 'Sportheim Musterstadt',
            'sortierung' => 0,
            'aktiv' => '',
        ], $this->context());

        $repo = new SportheimRepository($this->pdo());
        self::assertNotNull($repo->find($sportheimId));
        self::assertFalse(in_array($sportheimId, array_column($repo->findAktive(), 'id'), true));
    }

    public function testAddRaumPersistsAsEventAndProjection(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);

        $raumId = $this->sportheimService()->addRaum($sportheimId, [
            'name' => 'Gastraum',
            'kuerzel' => 'GR',
            'aktiv' => '1',
        ], $this->context());

        $raum = new SportheimRaumRepository($this->pdo())->find($raumId);
        self::assertNotNull($raum);
        self::assertSame('Gastraum', $raum['name']);
        self::assertSame($sportheimId, (int) $raum['sportheim_id']);
    }

    public function testDeleteRaumIsRejectedWhileVermietungReferencesIt(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $raumId = $this->createSportheimRaum($sportheimId);
        $this->createVermietung($sportheimId, '2026-08-01 10:00:00', '2026-08-01 12:00:00', 'Feier', [$raumId]);

        try {
            $this->sportheimService()->deleteRaum($raumId, $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('id', $e->getErrors());
        }

        self::assertNotNull(new SportheimRaumRepository($this->pdo())->find($raumId));
    }

    public function testDeleteRaumSucceedsWithoutReferencingVermietung(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $raumId = $this->createSportheimRaum($sportheimId);

        $this->sportheimService()->deleteRaum($raumId, $this->context());

        self::assertNull(new SportheimRaumRepository($this->pdo())->find($raumId));
    }
}
