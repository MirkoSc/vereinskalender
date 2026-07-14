<?php

declare(strict_types=1);

namespace App\Http;

enum MatchType
{
    case Matched;
    case NotFound;
    case MethodNotAllowed;
}
