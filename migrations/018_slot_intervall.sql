-- Recurrence interval in weeks for training slots: 1 = every week (the
-- implicit behaviour until now), 2 = every other week, and so on. The
-- rhythm is anchored on the Monday of the gueltig_ab week, see
-- SlotExpander::expand() and CLAUDE.md section 3.
--
-- DEFAULT 1 backfills every existing row as weekly and keeps the migration
-- backwards compatible for one version (CLAUDE.md section 9): the previous
-- release does not list the column in its INSERT, so the default applies.

ALTER TABLE training_slot
    ADD COLUMN intervall_wochen TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER wochentage;
