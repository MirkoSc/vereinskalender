-- Bereiche (Issue #27) become a managed projection instead of the fixed
-- App\Domain\Bereich enum: admins can rename, sort, deactivate and add new
-- ones (e.g. A-/B-Jugend) without a code change.
--
-- The eight bereiche corresponding to the former enum values are seeded as
-- real EVENTS (quelle='system'), not just projection rows: the event log is
-- the source of truth (CLAUDE.md section 4), so a rebuild must be able to
-- reproduce them. Their `kuerzel` equals the old enum value; this is the
-- stable key TeamProjector::normalizePayload() uses to upcast legacy team
-- events (payload key `bereich` as a string) to `bereich_id` - by reading
-- these CREATED events' immutable payloads directly from the event log, not
-- by looking up the (renameable) live `bereich` table.

CREATE TABLE bereich (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    kuerzel VARCHAR(10) NOT NULL,
    sortierung INT NOT NULL DEFAULT 0,
    aktiv TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_g = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_g, 'created', JSON_OBJECT('name', 'G-Jugend', 'kuerzel', 'G', 'sortierung', 10, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_g, 'G-Jugend', 'G', 10, 1);

INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_f = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_f, 'created', JSON_OBJECT('name', 'F-Jugend', 'kuerzel', 'F', 'sortierung', 20, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_f, 'F-Jugend', 'F', 20, 1);

INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_e = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_e, 'created', JSON_OBJECT('name', 'E-Jugend', 'kuerzel', 'E', 'sortierung', 30, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_e, 'E-Jugend', 'E', 30, 1);

INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_d = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_d, 'created', JSON_OBJECT('name', 'D-Jugend', 'kuerzel', 'D', 'sortierung', 40, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_d, 'D-Jugend', 'D', 40, 1);

INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_c = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_c, 'created', JSON_OBJECT('name', 'C-Jugend', 'kuerzel', 'C', 'sortierung', 50, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_c, 'C-Jugend', 'C', 50, 1);

-- new with Issue #27 (B-/A-Jugend were missing from the old fixed enum)
INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_b = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_b, 'created', JSON_OBJECT('name', 'B-Jugend', 'kuerzel', 'B', 'sortierung', 60, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_b, 'B-Jugend', 'B', 60, 1);

INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_a = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_a, 'created', JSON_OBJECT('name', 'A-Jugend', 'kuerzel', 'A', 'sortierung', 70, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_a, 'A-Jugend', 'A', 70, 1);

INSERT INTO aggregate_sequence (id) VALUES (NULL);
SET @bereich_herren = LAST_INSERT_ID();
INSERT INTO event (aggregat_typ, aggregat_id, event_typ, payload, editor_name, ip, quelle, erstellt_am)
VALUES ('bereich', @bereich_herren, 'created', JSON_OBJECT('name', 'Herren', 'kuerzel', 'Herren', 'sortierung', 80, 'aktiv', TRUE), 'migration', '', 'system', '2026-07-18 00:00:00');
INSERT INTO bereich (id, name, kuerzel, sortierung, aktiv) VALUES (@bereich_herren, 'Herren', 'Herren', 80, 1);

-- team.bereich (string) stays for one version (rollback compatibility,
-- CLAUDE.md section 10, same pattern as migration 008); team.bereich_id is
-- the new source of truth and is nullable so upcasting can leave it NULL for
-- an event whose bereich string matches no seed (should not happen for the
-- values above, kept for defensive determinism, see TeamProjector).
ALTER TABLE team
    ADD COLUMN bereich_id BIGINT UNSIGNED NULL AFTER bereich,
    ADD INDEX idx_team_bereich (bereich_id);

UPDATE team t JOIN bereich b ON b.kuerzel = t.bereich SET t.bereich_id = b.id;
