## Zusammenfassung

Closes #

## Entscheidungen
<!-- Was ohne Rückfrage festgelegt wurde, mit Begründung -->

## Manuell zu prüfen
- [ ] Mobil 360 px (Tag und Liste)
- [ ] PC > 1100 px (Woche mit Platz-Spalten, Monat)
- [ ] Offline (Banner, Kalender und Verfügbarkeit aus dem Bundle)
- [ ] 

## Checkliste
- [ ] Tests grün (PHPUnit, `node --test`), keine Deprecations
- [ ] Schreibwege über Events, Upcasting für alte Events, Replay deterministisch
- [ ] Offline-Ports/Parity-Fixtures nachgezogen (falls betroffen)
- [ ] Kein Inline-JS, keine `on*`-Attribute (CSP)
- [ ] Migration neu (keine alte geändert), Nummer > höchste auf `main`, rückwärtskompatibel
- [ ] setup.php neu erzeugt (falls ReleaseDownloader/Template betroffen)
- [ ] CLAUDE.md aktualisiert (falls Architektur betroffen)
