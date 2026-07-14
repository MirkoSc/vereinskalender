-- Import source: the config fields (team_id, ics_url, aktiv) are a
-- projection managed through events. The run-status columns (letzter_lauf,
-- letzter_status, fehlertext) are technical (CLAUDE.md section 4): the
-- import runner writes them directly, they are NOT part of event payloads
-- and are deliberately transient across rebuilds.

CREATE TABLE import_source (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    ics_url VARCHAR(500) NOT NULL,
    aktiv TINYINT(1) NOT NULL DEFAULT 1,
    letzter_lauf DATETIME NULL,
    letzter_status VARCHAR(16) NULL, -- 'ok' | 'fehler'
    fehlertext TEXT NULL,
    INDEX idx_import_source_team (team_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
