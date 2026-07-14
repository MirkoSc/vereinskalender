<?php

declare(strict_types=1);

namespace App\Service\Migration;

final readonly class MigrationResult
{
    /**
     * @param list<Migration> $applied
     */
    public function __construct(
        public array $applied,
        public int $fromVersion,
        public int $toVersion,
    ) {
    }
}
