<?php

declare(strict_types=1);

namespace App\Service\Import;

final readonly class ImportSourceResult
{
    public function __construct(
        public int $sourceId,
        public bool $ok,
        public int $inserted = 0,
        public int $updated = 0,
        public int $cancelled = 0,
        public int $deleted = 0,
        public int $skipped = 0,
        public ?string $fehlertext = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quelle' => $this->sourceId,
            'status' => $this->ok ? 'ok' : 'fehler',
            'neu' => $this->inserted,
            'aktualisiert' => $this->updated,
            'abgesagt' => $this->cancelled,
            'geloescht' => $this->deleted,
            'unveraendert' => $this->skipped,
            'fehlertext' => $this->fehlertext,
        ];
    }
}
