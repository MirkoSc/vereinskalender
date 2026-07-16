-- Issue #11: Plätze bekommen ein Kürzel wie Teams, für die Text-Beschriftung
-- in der "nach Platz"-Gruppierung im Spielplan. Bestehende Plätze starten
-- mit leerem Kürzel (PitchProjector upcastet alte Events genauso) - das
-- Frontend fällt dabei auf den vollen Platznamen zurück, bis ein Admin das
-- Kürzel im Platz-Formular nachträgt (dort ist es Pflichtfeld).

ALTER TABLE pitch
    ADD COLUMN kuerzel VARCHAR(10) NOT NULL DEFAULT '' AFTER name;
