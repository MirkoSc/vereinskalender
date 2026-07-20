-- Issue #36: Sportheim-Vermietung als eigener Termintyp. Drei neue
-- Projektionen (PK aus dem Event, keine FK-Constraints, wie 003/010) plus
-- eine nullable FK auf pitch. Vermietungen blockieren nie Trainings/Spiele
-- (BookingService behandelt sie ausschliesslich als Hinweis); die Tabellen
-- brauchen deshalb keine Seed-Events (anders als bereich).

CREATE TABLE sportheim (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    venue_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    adresse VARCHAR(255) NULL,
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_sportheim_venue (venue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sportheim_raum (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    sportheim_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    kuerzel VARCHAR(10) NOT NULL,
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    INDEX idx_sportheim_raum_sportheim (sportheim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- raum_ids: JSON list of sportheim_raum ids, 0..n. Empty list = whole house.
CREATE TABLE vermietung (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    sportheim_id BIGINT UNSIGNED NOT NULL,
    raum_ids JSON NOT NULL,
    von DATETIME NOT NULL,
    bis DATETIME NOT NULL,
    titel VARCHAR(255) NOT NULL,
    kontakt VARCHAR(255) NULL,
    bemerkung TEXT NULL,
    INDEX idx_vermietung_sportheim (sportheim_id),
    INDEX idx_vermietung_von (von),
    INDEX idx_vermietung_bis (bis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Not every pitch is at a clubhouse; NULL is explicitly allowed. Legacy
-- events without the field are upcast to NULL on replay (PitchProjector,
-- analog to farbe/kuerzel) - the Replayer's reference check skips NULL FK
-- values, so old events never become orphaned by this addition.
ALTER TABLE pitch
    ADD COLUMN sportheim_id BIGINT UNSIGNED NULL AFTER venue_id,
    ADD INDEX idx_pitch_sportheim (sportheim_id);
