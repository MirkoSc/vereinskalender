<?php

declare(strict_types=1);

namespace App\Domain;

enum EventSource: string
{
    case Web = 'web';
    case Admin = 'admin';
    case Import = 'import';
    case System = 'system';
}
