-- Projections of the event log (CLAUDE.md sections 4/5): the PK comes from
-- the event's aggregat_id (NO AUTO_INCREMENT), deletions are delete events.
-- Deliberately no FK constraints: referential integrity is validated in the
-- write path and by the replay (skip + report), so shadow-table rebuilds can
-- swap tables atomically without constraint bookkeeping.

CREATE TABLE venue (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    farbe CHAR(7) NOT NULL,
    adresse VARCHAR(255) NOT NULL,
    default_pitch_id BIGINT UNSIGNED NULL,
    sortierung INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE venue_begriff (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    venue_id BIGINT UNSIGNED NOT NULL,
    begriff VARCHAR(100) NOT NULL,
    sortierung INT NOT NULL DEFAULT 0,
    INDEX idx_venue_begriff_venue (venue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pitch (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    venue_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    typ VARCHAR(50) NOT NULL DEFAULT '',
    flutlicht TINYINT(1) NOT NULL DEFAULT 0,
    adresse VARCHAR(255) NULL,
    sortierung INT NOT NULL DEFAULT 0,
    INDEX idx_pitch_venue (venue_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    bereich VARCHAR(10) NOT NULL,
    name VARCHAR(100) NOT NULL,
    kuerzel VARCHAR(10) NOT NULL,
    farbe CHAR(7) NOT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    sortierung INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
