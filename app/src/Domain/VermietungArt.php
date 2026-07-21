<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Issue #63: a Sportheim appointment is not always a rental - cleaning and
 * meetings occupy the same rooms with exactly the same non-blocking
 * semantics, so they share the vermietung aggregate and differ only in this
 * field. The aggregate/table name stays 'vermietung' (renaming would be a
 * migration without value); the UI calls the umbrella term
 * "Sportheim-Termin" and each art carries its own label.
 *
 * Single source of truth for every art-dependent wording: PHP uses it in
 * EventSerializer/BookingService/AvailabilityCalculator, the client gets the
 * same strings via appData.vermietungArten (PublicController::stammdaten()),
 * so no label is ever maintained twice.
 */
enum VermietungArt: string
{
    case Vermietung = 'vermietung';
    case Putzen = 'putzen';
    case Sitzung = 'sitzung';

    /**
     * Prefixes the event title ("Putzen: Grundreinigung (GR)") and names the
     * art in dialogs, filters and the legend.
     */
    public function label(): string
    {
        return match ($this) {
            self::Vermietung => 'Vermietung',
            self::Putzen => 'Putzen',
            self::Sitzung => 'Sitzung',
        };
    }

    /**
     * Wording of the 🏠 hint on trainings/matches whose pitch belongs to the
     * affected Sportheim - "Sportheim vermietet" would be a lie for a
     * cleaning slot.
     */
    public function hinweis(): string
    {
        return match ($this) {
            self::Vermietung => 'Sportheim vermietet',
            self::Putzen => 'Sportheim wird gereinigt',
            self::Sitzung => 'Sitzung im Sportheim',
        };
    }

    /**
     * Upcast for events written before the field existed (CLAUDE.md section
     * 4); must match the DEFAULT of migration 017.
     */
    public static function fromPayload(mixed $value): self
    {
        return self::tryFrom((string) ($value ?? '')) ?? self::Vermietung;
    }

    /**
     * @return list<array{wert: string, label: string, hinweis: string}>
     */
    public static function alle(): array
    {
        return array_map(static fn(self $art): array => [
            'wert' => $art->value,
            'label' => $art->label(),
            'hinweis' => $art->hinweis(),
        ], self::cases());
    }
}
