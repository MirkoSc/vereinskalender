---
description: Ein GitHub-Issue nach dem Projekt-Workflow umsetzen
argument-hint: <issue-nummer>
---
Setze GitHub-Issue #$ARGUMENTS im Vereinskalender um. Halte dich strikt an
diesen Ablauf (Hintergrund: `docs/VORGEHEN.md`):

1. Issue lesen (GitHub-MCP-Tools `mcp__github__issue_read`, inkl.
   Kommentare). CLAUDE.md kennst du bereits; lies daraus gezielt die im
   Issue genannten Abschnitte und den Code, den das Issue betrifft.
   Code zum Übernehmen: das Schwesterprojekt daneben
   (`../mitgliederverwaltung`), falls vorhanden.
2. Prüfe, dass alle Issues unter „Abhängig von" geschlossen sind. Wenn
   nicht: abbrechen und das sagen.
3. Kurzer Plan (betroffene Dateien, Migration, Tests). Interaktiv: bei
   fachlicher Unklarheit fragen. Automatisch: die einfachste
   CLAUDE.md-konforme Lösung wählen und im PR unter „Entscheidungen"
   begründen.
4. Branch `issue-$ARGUMENTS` von aktuellem `main` (interaktiv:
   `git switch main && git pull && git switch -c issue-$ARGUMENTS`).
5. Implementieren inkl. der Pflicht-Tests aus CLAUDE.md §11 für diesen
   Umfang. Bei jeder UI-Arbeit den Skill `frontend-design` laden; die
   bestehende Gestaltung (CLAUDE.md §8: mobile-first, Touch-Ziele ≥ 44 px,
   „Farbe ist nie das einzige Signal", CSP ohne Inline-JS) hat Vorrang.
   **Migrationen:** neue Datei `NNN_name.sql` mit der nächsten freien
   Nummer nach `main`. Der Migrator wendet nur Nummern > `MAX(version)` an –
   eine kleinere Nummer als die höchste auf `main` würde auf bestehenden
   Installationen nie laufen.
6. Prüfen (eigenes Docker-Projekt, damit parallele Agenten sich nicht
   stören):
   - `docker compose -p issue-$ARGUMENTS run --rm --no-deps app composer install` (falls `vendor/` fehlt)
   - `docker compose -p issue-$ARGUMENTS run --rm app vendor/bin/phpunit`
   - `node --test tests/js/*.test.js`
   - setup.php betroffen: `docker compose -p issue-$ARGUMENTS run --rm --no-deps app php bin/build_setup.php`
     und das Ergebnis committen (CI prüft, dass es aktuell ist).
   - Offline-Ports betroffen: Parity-Fixtures neu erzeugen und den Diff
     bewusst prüfen (CLAUDE.md §11).
   Alles grün, ohne Deprecations. Danach
   `docker compose -p issue-$ARGUMENTS down -v`.
7. Architektur/Datenmodell betroffen: CLAUDE.md im selben Branch
   aktualisieren (CLAUDE.md §11). Neue Folge-Issues nur vorschlagen (im
   PR), nicht selbst anlegen.
8. Commits auf Englisch. PR gegen `main` nach `.github/pull_request_template.md`:
   Zusammenfassung, `Closes #$ARGUMENTS`, „Entscheidungen" (was ohne
   Rückfrage festgelegt wurde), Prüfliste für manuelle Tests.
9. Kurze Liste am Ende: was gemacht, was manuell zu testen ist, offene
   Punkte.
