<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who caused a write: carried by every event (CLAUDE.md section 5).
 */
final readonly class EventContext
{
    public function __construct(
        public string $editorName,
        public string $ip,
        public EventSource $source,
    ) {
    }
}
