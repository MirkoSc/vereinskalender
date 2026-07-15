<?php

declare(strict_types=1);

namespace App\Service\Kalender;

/**
 * One structured conflicting occurrence, backing the formatted German
 * sentence in ConflictCheckResult::$conflicts/$warnings. Kept alongside the
 * strings (not instead of) so the write path (createSlot/updateSlot,
 * SaisonService) can keep working with plain messages while the booking
 * dialog groups by verursacher (issue #9).
 */
final readonly class Conflict
{
    public function __construct(
        public string $typ, // 'slot' | 'match' | 'restriktion'
        public int $verursacherId, // slot id / match id / restriction id
        public string $label, // team names, opponent, or Grund
        public string $datum, // Y-m-d
        public string $von, // H:i
        public string $bis, // H:i
        public bool $istWarnung,
        public string $nachricht,
    ) {
    }
}
