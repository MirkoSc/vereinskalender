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
        public int $purged = 0,
    ) {
    }

    /**
     * Purged matches (a reset's own removal, CLAUDE.md section 6) are
     * counted separately from $deleted (the feed-rebuild cleanup) so the
     * two causes stay distinguishable in the admin log.
     */
    public function withPurged(int $purged): self
    {
        return new self(
            $this->sourceId,
            $this->ok,
            $this->inserted,
            $this->updated,
            $this->cancelled,
            $this->deleted,
            $this->skipped,
            $this->fehlertext,
            $purged,
        );
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
            'entfernt' => $this->purged,
            'unveraendert' => $this->skipped,
            'fehlertext' => $this->fehlertext,
        ];
    }
}
