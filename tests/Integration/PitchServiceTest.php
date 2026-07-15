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
                'farbe' => '#123456',
            ], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('farbe', $e->getErrors());
        }
    }

    public function testDeleteReemitsCurrentColorInFullPicturePayload(): void
    {
        $venueId = $this->createVenue();
        $pitchId = $this->createPitch($venueId, 'Rasenplatz 3', '#d1247f');

        $this->pitchService()->delete($pitchId, $this->context());

        $events = $this->pdo()
            ->query('SELECT payload FROM event WHERE aggregat_typ = "pitch" AND event_typ = "deleted" ORDER BY id DESC LIMIT 1')
            ->fetch();
        $payload = json_decode((string) $events['payload'], true);
        self::assertSame('#d1247f', $payload['farbe']);
    }

    public function testPitchDefaultColorIsInPalette(): void
    {
        self::assertTrue(Palette::isValid(Palette::PITCH_DEFAULT));
    }
}
