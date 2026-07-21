-- Issue #65: Spielfrei-Termine (kein Spiel, aber ein regulärer Feed-Eintrag
-- ohne LOCATION, dessen SUMMARY einen konfigurierbaren Begriff trägt) werden
-- als eigene Kategorie neben "auswärts" geführt statt sie damit zu verwechseln.
-- DEFAULT 0 muss dem Upcast in MatchProjector::normalizePayload() entsprechen
-- (legacy Match-Events kennen das Feld nicht).

ALTER TABLE `match`
    ADD COLUMN spielfrei TINYINT(1) NOT NULL DEFAULT 0 AFTER heimspiel;
