<?php

declare(strict_types=1);

namespace App\Service\Migration;

/**
 * A single migration file, named NNN_snake_case_name.sql.
 */
final readonly class Migration
{
    private const string FILENAME_PATTERN = '/^(\d{3})_([a-z0-9_]+)\.sql$/';

    public function __construct(
        public int $version,
        public string $name,
        public string $path,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $filename = basename($path);
        if (preg_match(self::FILENAME_PATTERN, $filename, $matches) !== 1) {
            throw new MigrationException(sprintf(
                'Invalid migration filename "%s" (expected NNN_snake_case_name.sql)',
                $filename,
            ));
        }

        return new self((int) $matches[1], $matches[2], $path);
    }

    /**
     * @return list<self> sorted ascending by version
     */
    public static function discover(string $dir): array
    {
        $migrations = [];
        foreach (glob($dir . '/*.sql') ?: [] as $path) {
            $migration = self::fromFile($path);
            if (isset($migrations[$migration->version])) {
                throw new MigrationException(sprintf(
                    'Duplicate migration version %03d: %s and %s',
                    $migration->version,
                    basename($migrations[$migration->version]->path),
                    basename($path),
                ));
            }
            $migrations[$migration->version] = $migration;
        }
        ksort($migrations);

        return array_values($migrations);
    }
}
