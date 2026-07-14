<?php

declare(strict_types=1);

namespace App\Domain;

enum EventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
