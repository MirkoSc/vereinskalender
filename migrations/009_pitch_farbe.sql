-- Issue #2: pitches get their own palette color, like teams and venues.
-- DEFAULT must match Palette::PITCH_DEFAULT (PitchProjector upcasts legacy
-- events the same way on replay).

ALTER TABLE pitch
    ADD COLUMN farbe CHAR(7) NOT NULL DEFAULT '#1a7f37' AFTER name;
