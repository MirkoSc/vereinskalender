<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repository\VermietungRepository;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #36: Vermietungen sind ein öffentlicher Ebene-2-Schreibpfad (analog
 * manuellen Spielen) - erzeugen/ändern/löschen als Events, aber OHNE jede
 * Konfliktprüfung: eine Vermietung blockiert nie (das wird in
 * BookingServiceTest::testVermietungNeverBlocksOrWarnsBooking() aus der
 * anderen Richtung geprüft).
 */
final class VermietungServiceTest extends DatabaseTestCase
{

    public function testCreateWithEmptyRoomListMeansWholeHouse(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);

        $id = $this->vermietungService()->create([
            'sportheim_id' => (string) $sportheimId,
            'von' => '2026-08-01T18:00',
            'bis' => '2026-08-01T22:00',
            'titel' => 'Geburtstagsfeier',
        ], $this->context());

        $vermietung = new VermietungRepository($this->pdo())->find($id);
        self::assertNotNull($vermietung);
        self::assertSame([], json_decode((string) $vermietung['raum_ids'], true));
        self::assertSame('2026-08-01 18:00:00', $vermietung['von']);
        self::assertSame('2026-08-01 22:00:00', $vermietung['bis']);
        self::assertSame('Geburtstagsfeier', $vermietung['titel']);
        self::assertNull($vermietung['kontakt']);
        self::assertNull($vermietung['bemerkung']);
    }

    public function testCreateWithRoomsPersistsRoomIds(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $raum1 = $this->createSportheimRaum($sportheimId, 'Gastraum', 'GR');
        $raum2 = $this->createSportheimRaum($sportheimId, 'Kegelbahn', 'KB');

        $id = $this->vermietungService()->create([
            'sportheim_id' => (string) $sportheimId,
            'raum_ids' => [(string) $raum1, (string) $raum2],
            'von' => '2026-08-01T18:00',
            'bis' => '2026-08-01T22:00',
            'titel' => 'Vereinsfeier',
            'kontakt' => 'Max Mustermann',
            'bemerkung' => 'Schlüssel liegt beim Hausmeister',
        ], $this->context());

        $vermietung = new VermietungRepository($this->pdo())->find($id);
        self::assertSame([$raum1, $raum2], json_decode((string) $vermietung['raum_ids'], true));
        self::assertSame('Max Mustermann', $vermietung['kontakt']);
        self::assertSame('Schlüssel liegt beim Hausmeister', $vermietung['bemerkung']);
    }

    public function testCreateRejectsRoomFromAnotherSportheim(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $otherSportheimId = $this->createSportheim($venueId, 'Anderes Sportheim');
        $foreignRaum = $this->createSportheimRaum($otherSportheimId);

        try {
            $this->vermietungService()->create([
                'sportheim_id' => (string) $sportheimId,
                'raum_ids' => [(string) $foreignRaum],
                'von' => '2026-08-01T18:00',
                'bis' => '2026-08-01T22:00',
                'titel' => 'Feier',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('raum_ids', $e->getErrors());
        }
    }

    public function testCreateRejectsInactiveSportheim(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        // deactivate directly via the event store (mirrors admin deactivation)
        $this->eventStore()->append(\App\Domain\AggregateType::Sportheim, $sportheimId, \App\Domain\EventType::Updated, [
            'venue_id' => $venueId,
            'name' => 'Sportheim Musterstadt',
            'adresse' => null,
            'sortierung' => 0,
            'aktiv' => false,
        ], $this->context());

        try {
            $this->vermietungService()->create([
                'sportheim_id' => (string) $sportheimId,
                'von' => '2026-08-01T18:00',
                'bis' => '2026-08-01T22:00',
                'titel' => 'Feier',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sportheim_id', $e->getErrors());
        }
    }

    public function testCreateRejectsEndBeforeStart(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);

        try {
            $this->vermietungService()->create([
                'sportheim_id' => (string) $sportheimId,
                'von' => '2026-08-01T22:00',
                'bis' => '2026-08-01T18:00',
                'titel' => 'Feier',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('von', $e->getErrors());
        }
    }

    public function testCreateRejectsMissingTitel(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);

        try {
            $this->vermietungService()->create([
                'sportheim_id' => (string) $sportheimId,
                'von' => '2026-08-01T18:00',
                'bis' => '2026-08-01T22:00',
                'titel' => '',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('titel', $e->getErrors());
        }
    }

    public function testUpdateChangesTimeAndTitle(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $id = $this->createVermietung($sportheimId, '2026-08-01 18:00:00', '2026-08-01 22:00:00', 'Alt');

        $this->vermietungService()->update($id, [
            'sportheim_id' => (string) $sportheimId,
            'von' => '2026-08-02T19:00',
            'bis' => '2026-08-02T23:00',
            'titel' => 'Neu',
        ], $this->context());

        $vermietung = new VermietungRepository($this->pdo())->find($id);
        self::assertSame('Neu', $vermietung['titel']);
        self::assertSame('2026-08-02 19:00:00', $vermietung['von']);
    }

    public function testDeleteRemovesProjectionRow(): void
    {
        $venueId = $this->createVenue();
        $sportheimId = $this->createSportheim($venueId);
        $id = $this->createVermietung($sportheimId, '2026-08-01 18:00:00', '2026-08-01 22:00:00');

        $this->vermietungService()->delete($id, $this->context());

        self::assertNull(new VermietungRepository($this->pdo())->find($id));

        $event = $this->pdo()
            ->query(sprintf(
                'SELECT * FROM event WHERE aggregat_typ = "vermietung" AND aggregat_id = %d ORDER BY id DESC LIMIT 1',
                $id,
            ))
            ->fetch();
        self::assertSame('deleted', $event['event_typ']);
    }
}
