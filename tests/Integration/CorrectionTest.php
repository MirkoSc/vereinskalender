<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Domain\EventType;
use App\Tests\Support\DatabaseTestCase;

final class CorrectionTest extends DatabaseTestCase
{
    /** @return array<string, mixed> */
    private static function teamPayload(string $name): array
    {
        return [
            'bereich' => 'D',
            'name' => $name,
            'kuerzel' => 'D1',
            'farbe' => '#8250df',
            'aktiv' => true,
            'sortierung' => 0,
        ];
    }

    public function testCorrectExcludesOriginalAndAppliesCopy(): void
    {
        $store = $this->eventStore();
        $original = $store->append(
            AggregateType::Team,
            null,
            EventType::Created,
            self::teamPayload('D1 Tippfehler'),
            $this->context(),
        );

        $correction = $store->correct($original->id, self::teamPayload('D1'), $this->context('admin'));

        // original: excluded but preserved
        $originalRow = $store->find($original->id);
        self::assertNotNull($originalRow);
        self::assertNotNull($originalRow->excludedAt);

        // correction: points back to the original, same type, admin source
        self::assertSame($original->id, $correction->korrekturVonEventId);
        self::assertSame($original->aggregateId, $correction->aggregateId);
        self::assertSame(EventType::Created, $correction->eventType);

        // projection immediately shows the corrected state
        $teams = $this->dumpTable('team');
        self::assertCount(1, $teams);
        self::assertSame('D1', $teams[0]['name']);

        // and a rebuild reproduces exactly the same state
        $before = $this->dumpTable('team');
        $state = $this->runRebuildToCompletion($this->rebuildService());
        self::assertSame([], $state->skipped);
        self::assertSame($before, $this->dumpTable('team'));
    }

    public function testCorrectingAnExcludedEventIsRejected(): void
    {
        $store = $this->eventStore();
        $event = $store->append(
            AggregateType::Team,
            null,
            EventType::Created,
            self::teamPayload('X'),
            $this->context(),
        );
        $store->exclude($event->id, 'admin', 'weg damit');

        $this->expectException(\RuntimeException::class);

        $store->correct($event->id, self::teamPayload('Y'), $this->context('admin'));
    }
}
