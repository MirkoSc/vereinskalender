-- Issue #12: Manuell angelegte Spiele (Freundschaftsspiele, Turniere) können
-- eine von "Anstoß + 2 Stunden" abweichende Dauer haben. Importierte Spiele
-- schreiben weiterhin NULL; Anzeige, Konfliktprüfung, Verfügbarkeit und
-- ICS-Export fallen dann auf Anstoß + 2 Stunden zurück (MatchProjector
-- upcastet alte Events ohne das Feld ebenfalls auf NULL).

ALTER TABLE `match`
    ADD COLUMN ende DATETIME NULL AFTER anstoss;
