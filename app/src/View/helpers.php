<?php

declare(strict_types=1);

/**
 * Escapes a value for safe HTML output.
 */
function e(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
