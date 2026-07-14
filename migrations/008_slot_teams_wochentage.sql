-- Training slots carry 1..n teams (joint training) and 1..n weekdays
-- (e.g. Tuesday + Thursday at the same time) as JSON-encoded lists.
-- The old single-value columns team_id/wochentag stay for one version
-- (rollback compatibility, CLAUDE.md section 10) and keep being written
-- with the first list element; they are dropped in a follow-up version.

ALTER TABLE training_slot
    ADD COLUMN team_ids VARCHAR(500) NOT NULL DEFAULT '[]' AFTER team_id,
    ADD COLUMN wochentage VARCHAR(64) NOT NULL DEFAULT '[]' AFTER wochentag;

UPDATE training_slot
SET team_ids = CONCAT('[', team_id, ']'),
    wochentage = CONCAT('[', wochentag, ']');
