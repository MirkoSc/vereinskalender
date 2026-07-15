<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Predefined color palette for teams, venues and pitches (CLAUDE.md section 4).
 * Color is never the only signal in the UI, so the palette favours
 * distinguishable hues over sheer quantity.
 */
final class Palette
{
    /** @var array<string, string> hex => German label */
    public const array COLORS = [
        '#1a7f37' => 'Grün',
        '#2da44e' => 'Hellgrün',
        '#0969da' => 'Blau',
        '#54aeff' => 'Hellblau',
        '#1b7c83' => 'Petrol',
        '#cf222e' => 'Rot',
        '#bc4c00' => 'Orange',
        '#bf8700' => 'Gelb',
        '#8250df' => 'Violett',
        '#d1247f' => 'Pink',
        '#775c3c' => 'Braun',
        '#57606a' => 'Grau',
    ];

    /**
     * Upcast target for pitch events written before migration 009, which
     * carried no color (CLAUDE.md section 4/5). Must match the DEFAULT in
     * that migration.
     */
    public const string PITCH_DEFAULT = '#1a7f37';

    public static function isValid(string $hex): bool
    {
        return isset(self::COLORS[$hex]);
    }
}
