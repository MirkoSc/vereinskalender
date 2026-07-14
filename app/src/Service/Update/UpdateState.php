<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * State of a running update step chain, persisted in
 * shared/update_state.json (CLAUDE.md section 10).
 */
final readonly class UpdateState
{
    /**
     * @param list<string> $meldungen German log lines for the admin UI
     */
    public function __construct(
        public string $aktuelleVersion,
        public ?string $zielVersion = null,
        public ?string $zipUrl = null,
        public ?string $checksumsUrl = null,
        public ?string $abgeschlossenerSchritt = null,
        public bool $fertig = false,
        public ?string $fehler = null,
        public array $meldungen = [],
    ) {
    }

    public function mit(
        ?string $abgeschlossenerSchritt = null,
        ?string $fehler = null,
        ?string $meldung = null,
        ?bool $fertig = null,
    ): self {
        return new self(
            aktuelleVersion: $this->aktuelleVersion,
            zielVersion: $this->zielVersion,
            zipUrl: $this->zipUrl,
            checksumsUrl: $this->checksumsUrl,
            abgeschlossenerSchritt: $abgeschlossenerSchritt ?? $this->abgeschlossenerSchritt,
            fertig: $fertig ?? $this->fertig,
            fehler: $fehler,
            meldungen: $meldung !== null ? [...$this->meldungen, $meldung] : $this->meldungen,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'aktuelle_version' => $this->aktuelleVersion,
            'ziel_version' => $this->zielVersion,
            'zip_url' => $this->zipUrl,
            'checksums_url' => $this->checksumsUrl,
            'abgeschlossener_schritt' => $this->abgeschlossenerSchritt,
            'fertig' => $this->fertig,
            'fehler' => $this->fehler,
            'meldungen' => $this->meldungen,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            aktuelleVersion: (string) ($data['aktuelle_version'] ?? ''),
            zielVersion: isset($data['ziel_version']) ? (string) $data['ziel_version'] : null,
            zipUrl: isset($data['zip_url']) ? (string) $data['zip_url'] : null,
            checksumsUrl: isset($data['checksums_url']) ? (string) $data['checksums_url'] : null,
            abgeschlossenerSchritt: isset($data['abgeschlossener_schritt']) ? (string) $data['abgeschlossener_schritt'] : null,
            fertig: (bool) ($data['fertig'] ?? false),
            fehler: isset($data['fehler']) ? (string) $data['fehler'] : null,
            meldungen: array_values(array_map(strval(...), (array) ($data['meldungen'] ?? []))),
        );
    }
}
