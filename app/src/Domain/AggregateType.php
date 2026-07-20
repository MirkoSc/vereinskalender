<?php

declare(strict_types=1);

namespace App\Domain;

enum AggregateType: string
{
    case Team = 'team';
    case Bereich = 'bereich';
    case Pitch = 'pitch';
    case Venue = 'venue';
    case VenueBegriff = 'venue_begriff';
    case TrainingSlot = 'training_slot';
    case SlotException = 'slot_exception';
    case PitchRestriction = 'pitch_restriction';
    case Match = 'match';
    case ImportSource = 'import_source';
    case TeamHomePitch = 'team_home_pitch';
    case Sportheim = 'sportheim';
    case SportheimRaum = 'sportheim_raum';
    case Vermietung = 'vermietung';
}
