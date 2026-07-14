<?php

declare(strict_types=1);

namespace App\Domain;

enum Bereich: string
{
    case G = 'G';
    case F = 'F';
    case E = 'E';
    case D = 'D';
    case C = 'C';
    case Herren = 'Herren';
}
