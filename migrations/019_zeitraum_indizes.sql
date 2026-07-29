-- Range indexes for the two projection tables that are queried by time on
-- every calendar request but only carried an index on their FK column.
--
-- training_slot.findOverlapping()   WHERE gueltig_ab <= ? AND gueltig_bis >= ?
-- pitch_restriction.findOverlapping() WHERE von < ? AND bis > ?
-- pitch_restriction.naechsterBeginnNach()/vorherigerBeginnVor()
--                                   SELECT MIN(von)/MAX(von) WHERE von >|< ?
--
-- The MIN/MAX lookups feed the Terminliste's stop condition (CLAUDE.md
-- section 7) and run on every nachlade batch, so their cost grows with the
-- stored history rather than with the requested range - the reason to add
-- these now rather than when the tables get big. `vermietung` already got
-- the same treatment in migration 014; these two older tables were simply
-- never brought along.
--
-- Purely additive, so the previous release keeps running against this schema
-- (CLAUDE.md section 9): indexes change plans, never results.

ALTER TABLE training_slot
    ADD INDEX idx_training_slot_gueltig (gueltig_ab, gueltig_bis);

ALTER TABLE pitch_restriction
    ADD INDEX idx_pitch_restriction_von (von),
    ADD INDEX idx_pitch_restriction_bis (bis);
