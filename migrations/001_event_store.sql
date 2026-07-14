-- Event store: append-only source of truth (CLAUDE.md section 5).
-- Events are never deleted; removal from history = setting excluded_at.

CREATE TABLE event (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    aggregat_typ VARCHAR(32) NOT NULL,
    aggregat_id BIGINT UNSIGNED NOT NULL,
    event_typ VARCHAR(16) NOT NULL,
    payload JSON NOT NULL,
    editor_name VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    quelle VARCHAR(16) NOT NULL,
    erstellt_am DATETIME NOT NULL,
    excluded_at DATETIME NULL,
    excluded_von VARCHAR(100) NULL,
    excluded_grund VARCHAR(255) NULL,
    korrektur_von_event_id BIGINT UNSIGNED NULL,
    INDEX idx_event_ip (ip),
    INDEX idx_event_editor (editor_name),
    INDEX idx_event_aggregat (aggregat_typ, aggregat_id),
    INDEX idx_event_erstellt (erstellt_am)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dedicated sequence for aggregate ids: the id is allocated when the event
-- is written and stored IN the event, so projections never auto-increment
-- (otherwise references would shift after exclusions + rebuild).
CREATE TABLE aggregate_sequence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
) ENGINE=InnoDB;
