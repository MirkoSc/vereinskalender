-- Issue #10: seasonal home pitch rules per team + manual-assignment flag on
-- match. team_home_pitch is a projection (PK from event aggregat_id, no FK
-- constraints, same rules as 003/004). gueltig_ab/gueltig_bis are BOTH
-- INCLUSIVE (like training_slot). DEFAULT 0 on pitch_manuell must match the
-- upcast in MatchProjector::normalizePayload (legacy match events carry no
-- flag).

CREATE TABLE team_home_pitch (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    team_id BIGINT UNSIGNED NOT NULL,
    pitch_id BIGINT UNSIGNED NOT NULL,
    gueltig_ab DATE NOT NULL,
    gueltig_bis DATE NOT NULL,
    INDEX idx_team_home_pitch_team (team_id),
    INDEX idx_team_home_pitch_pitch (pitch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `match`
    ADD COLUMN pitch_manuell TINYINT(1) NOT NULL DEFAULT 0 AFTER pitch_id;
