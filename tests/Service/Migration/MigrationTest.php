<?php

declare(strict_types=1);

namespace App\Tests\Service\Migration;

use App\Service\Migration\Migration;
use App\Service\Migration\MigrationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    public function testFromFileParsesVersionAndName(): void
    {
        $migration = Migration::fromFile('/some/dir/001_create_event_store.sql');

        self::assertSame(1, $migration->version);
        self::assertSame('create_event_store', $migration->name);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidFilenames(): array
    {
        return [
            ['1_foo.sql'],
            ['001-foo.sql'],
            ['001_Foo.sql'],
            ['001_foo.php'],
            ['001_.sql'],
        ];
    }

    #[DataProvider('invalidFilenames')]
    public function testRejectsInvalidFilenames(string $filename): void
    {
        $this->expectException(MigrationException::class);

        Migration::fromFile('/some/dir/' . $filename);
    }

    public function testDiscoverSortsNumerically(): void
    {
        $migrations = Migration::discover(__DIR__ . '/../../fixtures/migrations/valid');

        self::assertSame(
            [1, 2, 10],
            array_map(static fn(Migration $m): int => $m->version, $migrations),
        );
    }

    public function testDiscoverThrowsOnDuplicateVersions(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Duplicate migration version 002');

        Migration::discover(__DIR__ . '/../../fixtures/migrations/duplicate');
    }

    public function testDiscoverReturnsEmptyListForEmptyDir(): void
    {
        self::assertSame([], Migration::discover(__DIR__ . '/../../fixtures/migrations/empty'));
    }
}
