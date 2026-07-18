<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\AggregateType;
use App\Repository\BereichRepository;
use App\Service\Stammdaten\SortierungService;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Issue #27: drag&drop reorder for the flat master-data lists (bereiche,
 * teams, plätze, spielstätten). Only rows whose sortierung actually changes
 * get an Updated event - one write = one transaction (CLAUDE.md section 4).
 */
final class SortierungServiceTest extends DatabaseTestCase
{
    private function sortierungService(): SortierungService
    {
        return new SortierungService($this->pdo(), $this->eventStore(), $this->projectorRegistry());
    }

    public function testReorderAssignsAscendingSortierungAndWritesOneEventPerMovedRow(): void
    {
        // distinct from the migration-seeded bereiche's sortierung (10-80),
        // so this test's expectations don't depend on what else exists
        $a = $this->createBereich('Alpha', 'AL', 1000);
        $b = $this->createBereich('Beta', 'BE', 2000);
        $c = $this->createBereich('Gamma', 'GA', 3000);

        $countBefore = (int) $this->pdo()
            ->query('SELECT COUNT(*) FROM event WHERE aggregat_typ = "bereich" AND event_typ = "updated"')
            ->fetchColumn();

        // c moves to the front; a and b keep their relative order
        $this->sortierungService()->reorder(AggregateType::Bereich, [$c, $a, $b], $this->context());

        $repo = new BereichRepository($this->pdo());
        $sortierungC = (int) $repo->find($c)['sortierung'];
        $sortierungA = (int) $repo->find($a)['sortierung'];
        $sortierungB = (int) $repo->find($b)['sortierung'];
        self::assertLessThan($sortierungA, $sortierungC, 'c is now first');
        self::assertLessThan($sortierungB, $sortierungA, 'a is now second');

        // a and b each moved to a new sortierung value too (relative
        // position shifted); only c "stays first" but its sortierung VALUE
        // still changed - so all three actually moved
        $countAfter = (int) $this->pdo()
            ->query('SELECT COUNT(*) FROM event WHERE aggregat_typ = "bereich" AND event_typ = "updated"')
            ->fetchColumn();
        self::assertSame($countBefore + 3, $countAfter);
    }

    public function testReorderWithUnchangedOrderWritesNoEvents(): void
    {
        $a = $this->createBereich('Alpha', 'AL', 10);
        $b = $this->createBereich('Beta', 'BE', 20);

        $countBefore = (int) $this->pdo()
            ->query('SELECT COUNT(*) FROM event WHERE aggregat_typ = "bereich" AND event_typ = "updated"')
            ->fetchColumn();

        // already in exactly this order with sortierung 10/20 - a no-op
        $this->sortierungService()->reorder(AggregateType::Bereich, [$a, $b], $this->context());

        $countAfter = (int) $this->pdo()
            ->query('SELECT COUNT(*) FROM event WHERE aggregat_typ = "bereich" AND event_typ = "updated"')
            ->fetchColumn();
        self::assertSame($countBefore, $countAfter);
    }

    public function testReorderWithUnknownIdIsRejected(): void
    {
        $a = $this->createBereich('Alpha', 'AL', 10);

        try {
            $this->sortierungService()->reorder(AggregateType::Bereich, [$a, 999999], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ids', $e->getErrors());
        }
    }

    public function testReorderWithEmptyListIsRejected(): void
    {
        try {
            $this->sortierungService()->reorder(AggregateType::Bereich, [], $this->context());
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('ids', $e->getErrors());
        }
    }
}
