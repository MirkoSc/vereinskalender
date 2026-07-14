<?php

declare(strict_types=1);

namespace App\Service\Kalender;

/**
 * Resolves a raw ICS LOCATION text to a home venue (CLAUDE.md section 8):
 * the first venue_begriff (by sortierung) contained case-insensitively in
 * the ort_text wins; no hit means away match. Resolution happens at display
 * time so new keywords apply retroactively; the SAME service is used by the
 * display AND the import.
 */
final readonly class VenueMatcher
{
    /**
     * @param list<array{begriff: string, venue_id: int}> $begriffe sorted by sortierung
     */
    public function __construct(private array $begriffe)
    {
    }

    public static function fromDatabase(\PDO $pdo): self
    {
        $rows = $pdo
            ->query('SELECT begriff, venue_id FROM venue_begriff ORDER BY sortierung, begriff')
            ->fetchAll();

        return new self(array_map(
            static fn(array $row): array => [
                'begriff' => (string) $row['begriff'],
                'venue_id' => (int) $row['venue_id'],
            ],
            $rows,
        ));
    }

    /**
     * @return int|null the matched venue id, null = away
     */
    public function match(string $ortText): ?int
    {
        if (trim($ortText) === '') {
            return null;
        }

        foreach ($this->begriffe as $begriff) {
            if (mb_stripos($ortText, $begriff['begriff']) !== false) {
                return $begriff['venue_id'];
            }
        }

        return null;
    }
}
