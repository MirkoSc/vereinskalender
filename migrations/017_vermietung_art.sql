-- Issue #63: Sportheim-Termine sind nicht immer Vermietungen - Putzen und
-- Sitzungen belegen dieselben Räume mit exakt derselben Nicht-Blockade-
-- Semantik und teilen sich deshalb das vermietung-Aggregat. Der Tabellenname
-- bleibt 'vermietung' (Umbenennen wäre eine Migration ohne Gegenwert).
-- DEFAULT 'vermietung' muss dem Upcast in
-- VermietungProjector::normalizePayload() entsprechen (Alt-Events kennen das
-- Feld nicht).

ALTER TABLE vermietung
    ADD COLUMN art VARCHAR(16) NOT NULL DEFAULT 'vermietung' AFTER sportheim_id; -- 'vermietung' | 'putzen' | 'sitzung'
