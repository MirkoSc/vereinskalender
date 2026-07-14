<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * 'gesperrt': the conflict check rejects new bookings.
 * 'eingeschraenkt': booking is allowed, but the dialog shows a warning
 * and affected occurrences carry a visible marker (CLAUDE.md section 4).
 */
enum RestrictionArt: string
{
    case Gesperrt = 'gesperrt';
    case Eingeschraenkt = 'eingeschraenkt';
}
