-- Projections for pitch occupancy and match schedule (CLAUDE.md section 4).
-- Same rules as 003: PK from the event's aggregat_id, no FK constraints.

CREATE TABLE training_slot (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    pitch_id BIGINT UNSIGNED NOT NULL,
    wochentag TINYINT UNSIGNED NOT NULL, -- 1 = Montag ... 7 = Sonntag (ISO-8601)
    beginn TIME NOT NULL,
    ende TIME NOT NULL,
    gueltig_ab DATE NOT NULL,
    gueltig_bis DATE NOT NULL,
    INDEX idx_training_slot_pitch (pitch_id),
    INDEX idx_training_slot_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slot_exception (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    slot_id BIGINT UNSIGNED NOT NULL,
    datum DATE NOT NULL,
    grund VARCHAR(255) NOT NULL DEFAULT '',
    INDEX idx_slot_exception_slot (slot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pitch_restriction (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    pitch_id BIGINT UNSIGNED NOT NULL,
    von DATETIME NOT NULL,
    bis DATETIME NOT NULL,
    art VARCHAR(16) NOT NULL, -- 'gesperrt' | 'eingeschraenkt'
    grund VARCHAR(255) NOT NULL,
    INDEX idx_pitch_restriction_pitch (pitch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Filled by the ICS import (milestone 4); the events API reads it already.
CREATE TABLE `match` (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    anstoss DATETIME NOT NULL,
    gegner VARCHAR(150) NOT NULL,
    heimspiel TINYINT(1) NOT NULL DEFAULT 0,
    ort_text VARCHAR(255) NOT NULL DEFAULT '',
    pitch_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'geplant', -- 'geplant' | 'abgesagt'
    import_source_id BIGINT UNSIGNED NULL,
    ics_uid VARCHAR(255) NOT NULL DEFAULT '',
    ics_sequence INT NOT NULL DEFAULT 0,
    sync_hash CHAR(64) NOT NULL DEFAULT '',
    UNIQUE KEY uq_match_source_uid (import_source_id, ics_uid),
    INDEX idx_match_team (team_id),
    INDEX idx_match_anstoss (anstoss),
    INDEX idx_match_pitch (pitch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Technical key/value table (NOT a projection): config values live in the
-- DB so they are part of every backup (CLAUDE.md section 4).
CREATE TABLE setting (
    `key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO setting (`key`, `value`) VALUES
    ('nutzungszeiten_von', '08:00'),
    ('nutzungszeiten_bis', '22:00'),
    ('auswaerts_farbe', '#57606a');
