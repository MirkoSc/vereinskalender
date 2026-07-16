<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Palette;
use App\Repository\PitchRepository;
use App\Repository\VenueRepository;
use App\Service\Stammdaten\PitchService;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #2: pitches get a palette color like teams and venues, validated the
 * same way (CLAUDE.md section 4).
 */
final class PitchServiceTest extends DatabaseTestCase
{
    private function pitchService(): PitchService
    {
        $pdo = $this->pdo();

        return new PitchService($this->eventStore(), new PitchRepository($pdo), new VenueRepository($pdo));
    }

    public function testCreateWithValidPaletteColorSucceeds(): void
    {
        $venueId = $this->createVenue();

        $id = $this->pitchService()->create([
            'venue_id' => (string) $venueId,
            'name' => 'Rasenplatz 2',
            'kuerzel' => 'R2',
            'farbe' => '#bc4c00',
        ], $this->context());

        $pitch = new PitchRepository($this->pdo())->find($id);
        self::assertSame('#bc4c00', $pitch['farbe']);
    }

    public function testCreateWithInvalidColorIsRejected(): void
    {
        $venueId = $this->createVenue();

        try {
            $this->pitchService()->create([
                'venue_id' => (string) $venueId,
                'name' => 'Rasenplatz 2',
                'kuerzel' => 'R2',
                'farbe' => '#123456',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('farbe', $e->getErrors());
        }
    }

    /**
     * Issue #11: Plätze bekommen ein Kürzel wie Teams, für die Text-
     * Beschriftung der "nach Platz"-Gruppierung im Spielplan.
     */
    public function testCreateWithoutKuerzelIsRejected(): void
    {
        $venueId = $this->createVenue();

        try {
            $this->pitchService()->create([
                'venue_id' => (string) $venueId,
                'name' => 'Rasenplatz 2',
                'kuerzel' => '',
                'farbe' => '#bc4c00',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('kuerzel', $e->getErrors());
        }
    }

    public function testCreateWithTooLongKuerzelIsRejected(): void
    {
        $venueId = $this->createVenue();

        try {
            $this->pitchService()->create([
                'venue_id' => (string) $venueId,
                'name' => 'Rasenplatz 2',
                'kuerzel' => 'ZuLangesKuerzel',
                'farbe' => '#bc4c00',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('kuerzel', $e->getErrors());
        }
    }

    public function testDeleteReemitsCurrentColorInFullPicturePayload(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId, 'Rasenplatz 3', '#d1247f', 'R3');

        $this->pitchService()->delete($pitchId, $this->context());

        $events = $this->pdo()
            ->query('SELECT payload FROM event WHERE aggregat_typ = "pitch" AND event_typ = "deleted" ORDER BY id DESC LIMIT 1')
            ->fetch();
        $payload = json_decode((string) $events['payload'], true);
        self::assertSame('#d1247f', $payload['farbe']);
        self::assertSame('R3', $payload['kuerzel']);
    }

    public function testPitchDefaultColorIsInPalette(): void
    {
        self::assertTrue(Palette::isValid(Palette::PITCH_DEFAULT));
    }
}
