<?php

declare(strict_types=1);

namespace App\Domain;

enum AggregateType: string
{
    case Team = 'team';
    case Pitch = 'pitch';
    case Venue = 'venue';
    case VenueBegriff = 'venue_begriff';
}
