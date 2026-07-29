<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Who caused a write: carried by every event (CLAUDE.md section 5).
 */
final readonly class EventContext
{
    /**
     * Must match the event.editor_name column (VARCHAR(100), migration 001).
     * The public write path takes this name from localStorage and does not
     * verify it further (trust model, CLAUDE.md section 5) - but "not
     * verified" concerns the identity, not the field: an over-long name
     * would hit a strict-mode "Data too long" and fail the whole write
     * transaction with a bare 500.
     */
    public const int MAX_EDITOR_NAME = 100;

    public function __construct(
        public string $editorName,
        public string $ip,
        public EventSource $source,
    ) {
    }
}
