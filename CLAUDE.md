# CLAUDE.md – Vereinskalender

Webkalender für einen Fußballverein (Spielgemeinschaft): Sportplatz-Belegung
(Training), Spielplan (ICS-Import), Verfügbarkeit. Die Anwendung ist
implementiert und in Betrieb; dieses Dokument ist die verbindliche
Architektur-Referenz. Bei Widersprüchen zwischen Code und Dokument gilt das
Dokument – oder es wird hier im selben PR bewusst geändert.
Änderungswünsche werden als GitHub Issues geführt, nicht in dieser Datei.

## 1. Harte Umgebungs-Constraints (niemals verletzen)

- Shared Hosting bei all-inkl: **kein SSH, kein Git, kein Composer, kein
  `exec()`/`shell_exec()`** auf dem Server.
- Deployment nur über Release-ZIPs (GitHub Releases) + setup.php/Self-Updater.
- **PHP 8.5**: fehler- und deprecation-frei; moderne Sprachfeatures (Enums,
  readonly, Promotion, `match`) aktiv nutzen. MySQL/MariaDB, PDO mit
  Prepared Statements, `ERRMODE_EXCEPTION`.
- **Kein einzelner Request darf lange laufen** – lange Vorgänge (Update,
  Import großer Dumps, Rebuild) sind idempotente Schrittketten aus kurzen
  Requests mit Statusdatei.
- Kein Build-Step auf dem Server; `vendor/` liegt im Release-ZIP.
- Kein SPA-Framework: server-gerendertes PHP + Vanilla JS + modernes CSS.

## 2. Verzeichnislayout (Server)

```
/web/                DocumentRoot (fix): index.php → require ../current/public/index.php
/current/            aktives Release (per rename() umgeschaltet)
/releases/vX.Y.Z/    app/, public/, vendor/, bin/, migrations/, VERSION
/shared/             überlebt Updates: config.php, var/backups/ (Rotation 10,
                     .htaccess-gesperrt), var/log/, maintenance.flag,
                     update_state.json
```

Repo spiegelt ein Release: `app/` (src/ mit Domain/Repository/Service, views/),
`public/`, `bin/`, `migrations/`, `setup.php` (Repo-Root, eigenes
Release-Asset), `tests/`.

## 3. Datenmodell

Fachliche Tabellen sind **Projektionen** des Event-Logs (Abschnitt 4): PK `id`
kommt aus dem Event (**kein AUTO_INCREMENT**), Löschungen sind delete-Events;
kein Soft-Delete-Feld. Technische Tabellen (admin, schema_version, rate_limit,
usage_stat, push_subscription, notification_queue, import_source-Laufstatus)
sind KEINE Projektionen.

- **bereich**: name, kuerzel, sortierung, aktiv. Eigenes Event-Aggregat
  (Issue #27) statt festem Enum: anlegen, umbenennen, sortieren,
  deaktivieren. Migration 013 seedet die acht bisherigen Werte (G-/F-/E-/D-/
  C-/B-/A-Jugend, Herren) als System-Events (quelle='system', kuerzel =
  ehemaliger Enum-Wert); B-/A-Jugend fehlten im alten Enum und sind damit
  neu. Löschen nur ohne referenzierende Teams (sonst deaktivieren – Historie
  bleibt, wie bei team.aktiv).
- **team**: bereich_id FK auf bereich, name (z. B. "E2"), kuerzel, farbe
  (aus vordefinierter Palette), aktiv, sortierung. Übergangsweise (eine
  Version, für Rollback) bleibt die Alt-Spalte bereich (String-Enum-Wert)
  bestehen und wird beim Schreiben mit dem Kürzel des gewählten Bereichs
  mitgeführt. Alt-Events mit nur dieser String-Payload werden beim Replay
  deterministisch auf die passende bereich_id gehoben – aufgelöst über die
  unveränderlichen System-Seed-Events im Event-Log (nicht über die
  umbenennbare bereich-Projektion, damit eine spätere Umbenennung alte
  Replays nicht verändert); bewusst kein references()-Eintrag, damit die
  Replay-Reihenfolge relativ zu den Bereich-Seed-Events keine Rolle spielt.
  Mehrere Mannschaften je Bereich; je Mannschaft eine import_source.
  Inaktive Teams verschwinden aus Filtern/Neuanlagen, Historie bleibt.
- **pitch**: venue_id FK (Heimverein), name, kuerzel (Pflichtfeld, max. 10
  Zeichen, für die Text-Beschriftung bei der Platz-Gruppierung im
  Spielplan), farbe (aus vordefinierter Palette), typ, flutlicht, adresse
  NULL (nur falls abweichend), sortierung. Alt-Events ohne Farbe bzw. ohne
  Kürzel (vor Einführung der jeweiligen Spalte) werden beim Replay
  deterministisch auf eine feste Default-Farbe bzw. ein leeres Kürzel
  gehoben (Upcasting, analog training_slot); das Frontend fällt bei leerem
  Kürzel auf den Platznamen zurück.
- **training_slot**: team_ids (Liste 1..n – gemeinsames Training ist EIN
  Slot), pitch_id FK, wochentage (Liste 1..n aus 1–7), beginn, ende,
  gueltig_ab, gueltig_bis. Wiederholungsregel, zur Laufzeit expandiert.
  Übergangsweise (eine Version, für Rollback) behält die Projektion die
  Alt-Spalten team_id/wochentag = erstes Listenelement; Alt-Events werden
  beim Replay per Payload-Normalisierung aufs Listenformat gehoben.
  **Bearbeiten ist öffentlich** (Ebene 2) mit Umfangs-Rückfrage:
  „alle Termine" (Updated-Event), „dieser und alle folgenden" (Split:
  Updated kürzt gueltig_bis + Created für die Fortsetzung, atomar in einer
  Transaktion), „nur dieser" (slot_exception-Event + Created eines
  Eintages-Slots, atomar).
- **slot_exception**: slot_id FK, datum, grund.
- **pitch_restriction**: pitch_id FK, von, bis,
  art ('gesperrt'|'eingeschraenkt'), grund (Pflicht). 'gesperrt' →
  Konfliktprüfung lehnt neue Belegungen ab; 'eingeschraenkt' → Belegen
  erlaubt, Buchungsdialog warnt mit Grund, Termine tragen Markierung.
- **match**: team_id FK, anstoss, ende NULL (nur bei manuellen Spielen
  gesetzt; der Import schreibt immer NULL; Anzeige, Konfliktprüfung,
  Verfügbarkeit und ICS-Export nutzen ende, sonst Fallback Anstoß + 2 Std.
  – zentral in `MatchDuration`; Alt-Events ohne Feld werden beim Replay
  deterministisch auf NULL gehoben), gegner, heimspiel, ort_text
  (ICS-LOCATION roh), pitch_id NULL (nur Heimspiele), pitch_manuell (true =
  manuelle Platz-Zuordnung, der Import fasst pitch_id dann nie an;
  Alt-Events ohne Feld werden beim Replay deterministisch auf false
  gehoben, Upcasting analog pitch.farbe), status ('geplant'|'abgesagt'),
  import_source_id NULL, ics_uid, ics_sequence, sync_hash.
  **UNIQUE(import_source_id, ics_uid)**.
  **Manuelle Spiele** (Freundschaftsspiele, Turniere): Kennzeichnung
  `import_source_id IS NULL` (ics_uid ''), API-Feld `manuell`. Anlegen/
  Bearbeiten/Löschen öffentlich (Ebene 2) als Events, Löschen =
  delete-Event; nur manuelle Spiele sind so editierbar, importierte lehnt
  der Server ab (Platz-Zuordnung über die eigene Route bleibt). Pflicht:
  Team, Anstoß, Gegner/Titel; Platz ODER ort_text (Platz gewählt →
  heimspiel + pitch_manuell; sonst heimspiel per VenueMatcher wie beim
  Import). Konfliktprüfung beim Schreiben: 'gesperrt' blockiert,
  Slot-/Spiel-Überlappung und 'eingeschraenkt' warnen mit Bestätigung.
  Bearbeiten erhöht ics_sequence (Kalender-Abos erkennen die Verlegung);
  Verlegung/Absage lösen Push aus wie bei Import-Spielen, Löschen nicht.
- **team_home_pitch**: team_id FK, pitch_id FK, gueltig_ab, gueltig_bis
  (beide INKLUSIVE, wie training_slot). Je Team überlappungsfrei (auch ein
  gemeinsamer Grenztag zählt als Überlappung). Pflege eingebettet im
  Team-Formular; der Saison-Assistent bietet die Übernahme in neue
  Zeiträume an (Abschnitt 5).
- **import_source**: team_id FK, ics_url, aktiv, letzter_lauf,
  letzter_status, fehlertext
- **venue** (Heimverein): name, farbe, adresse, default_pitch_id NULL,
  sortierung. Mehrere Heimvereine, je 1..n Plätze.
- **venue_begriff**: venue_id FK, begriff (Match-Keyword), sortierung.
  Mehrere Begriffe je Verein möglich.
- **admin**: username UNIQUE, password_hash
- **event**: siehe Abschnitt 4 – Quelle der Wahrheit.
- **setting** (key/value): Konfiguration in der DB, nicht in Dateien
  (landet so im Backup). U. a. Auswärts-Farbe, Nutzungszeiten, Update-Kanal,
  Admin-Mail.

## 4. Event Sourcing (Kern-Invarianten)

Append-only Tabelle **event** ist die Quelle der Wahrheit; Projektionen sind
jederzeit per Replay rekonstruierbar.

- **event**: id (BIGINT AUTO_INCREMENT = globale Reihenfolge), aggregat_typ,
  aggregat_id, event_typ ('created'|'updated'|'deleted'), payload JSON,
  editor_name, ip, quelle ('web'|'admin'|'import'|'system'), erstellt_am,
  excluded_at/excluded_von/excluded_grund NULL, korrektur_von_event_id NULL.
  Indizes: ip, editor_name, aggregat_typ+aggregat_id, erstellt_am.
- **Schreibpfad**: Ein Schreibvorgang = EINE Transaktion mit 1..n Events,
  die zugleich auf die Projektionen angewendet werden (Regelfall: ein Event;
  Serien-Split: zwei). Es gibt KEINEN Schreibweg an den Events vorbei –
  auch ICS-Import und Admin-Änderungen erzeugen Events.
- **Determinismus** (Voraussetzung für Replay):
  - `aggregat_id` kommt aus einer eigenen Sequenz-Tabelle und steht IM Event;
    Projektionen übernehmen sie als PK.
  - `payload` ist ein **Vollbild** des Zielzustands, kein Diff.
  - Ältere Payload-Formate werden beim Replay deterministisch normalisiert
    (Upcasting), z. B. team_id/wochentag → Listenformat.
  - Replay: Events in id-Reihenfolge; ausgeschlossene überspringen; Events
    mit fehlendem Aggregat/FK-Ziel überspringen und im **Replay-Report**
    listen. Gleicher Event-Bestand ⇒ gleiche Projektionen.
- **Events sind unveränderlich**: Entfernen = `excluded_at` setzen, niemals
  DELETE. Bearbeiten = Original ausschließen + korrigierte Kopie
  (quelle='admin', korrektur_von_event_id → Original).
- **Rebuild** (Admin): Schatten-Tabellen (`<name>_rebuild`), batchweise
  Schrittkette, atomarer `RENAME TABLE`-Tausch, danach Replay-Report.
- **DSGVO**: IPs in Events nach 90 Tagen anonymisieren (Cron, Setting);
  Zweck Missbrauchsabwehr steht in der Datenschutzerklärung.

## 5. Zugriffsmodell

1. **Lesen**: öffentlich, keine Session.
2. **Ändern (öffentlich)**: `editor_name` aus localStorage, wird bei jedem
   Schreib-Request mitgesendet; Server lehnt Schreiben ohne Namen ab, prüft
   ihn nicht weiter (Vertrauensmodell). Absicherung: Event-Historie +
   Rate-Limit pro IP (~30 Schreibzugriffe/Minute).
3. **Admin**: username + password_hash, PHP-Session. **Bootstrap-Regel**:
   Credentials aus config.php gelten NUR bei leerer admin-Tabelle; erster
   Login erzwingt Anlage eines echten Admins.

CSRF-Token für alle Schreibrouten. Passwörter nie loggen.

Admin-Funktionen: Bereiche/Teams/Plätze/Spielstätten-CRUD (Teams inkl.
eingebetteter Heimspielstätten-Regeln, Abschnitt 3; Sortierung in allen vier
Listen per Drag&Drop – Pointer Events, Touch-Ziel ≥ 44 px –, das Zahlenfeld
bleibt als Fallback), Import-Quellen, Event-Historie (Filter:
IP/Name/Typ/Quelle/Zeitraum; Einzel- und Massen-Ausschluss, Korrektur,
Ausschluss aufheben, Rebuild mit Fortschritt), Backup
erstellen/herunterladen, Update einspielen, Saison-Assistent (Hinweis auf
die Bereichs-Pflege beim Aufstieg mit Link in die Bereichs-Verwaltung, Teams
umbenennen/deaktivieren/anlegen, Import-URLs erneuern – fussball.de vergibt
pro Saison neue –, Slots und Heimspielstätten-Regeln der Vorsaison als
Kopiervorlage), Vereinswappen hochladen (Abschnitt 8).

### Dashboard & Monitoring

- **usage_stat** (datum, metrik, dimension NULL, anzahl; UNIQUE-Upsert):
  aggregierte Zähler, keine IPs/User-Agents/Cookies → kein Consent nötig.
  Serverseitig: Routen, API, ICS-Feeds, Offline-Bundle. Clientseitig per
  sendBeacon an `POST /api/stat` – NUR Whitelist fester Metriknamen,
  Rate-Limit gilt.
- Dashboard: Kennzahlen heute/7/30 Tage, Tagesverlauf, Top-Routen,
  Feature-Zähler, Feed-Abrufe, Push-Abos. CSS/SVG-Balken, keine
  Chart-Bibliothek.
- **Betriebs-Monitoring**: „Letzter Import" (grün < 30 Min, rot > 60 Min),
  Warnung je Feed bei Fehlern oder fehlenden Zukunftsterminen. Alarm-Mail
  (PHP `mail()`, Adresse als Setting, max. 1 Mail/Thema/Tag) bei
  Importfehlern und fehlgeschlagenen Update-Schritten.

## 6. ICS-Import

- KAS-Cron ruft `bin/import_ics.php` alle 10 Min per HTTP auf
  (Secret-Token aus config.php).
- Sync pro Event über `(import_source_id, ics_uid)`: unbekannt → INSERT;
  bekannt + sync_hash geändert → UPDATE (Verlegung: UID bleibt,
  DTSTART/SEQUENCE ändern sich); unverändert → skip. Nachlauf: im Feed
  fehlende UIDs → `status='abgesagt'`, NIEMALS hart löschen.
- Der Sync arbeitet ausschließlich auf `WHERE import_source_id = ?`
  (Kandidaten-Lookup UND Absage-Nachlauf) – **manuelle Spiele
  (`import_source_id IS NULL`) sind für ihn unsichtbar**: kein Update,
  keine Absage, kein Platz-Reflow (Regressionstest in IcsImportTest).
- `sync_hash` über anstoss + ort_text + gegner + summary-relevante Felder
  (NICHT pitch_id).
- Heimspiel-Erkennung via `VenueMatcher`; Platz steht NICHT im ICS →
  Zuordnung in fester Priorität: (1) manuelle Zuordnung (`pitch_manuell`)
  bleibt IMMER unangetastet – Auswahl „automatisch" setzt sie zurück;
  (2) die zum Anstoß-Datum gültige `team_home_pitch`-Regel (Grenztage
  inklusive); (3) `default_pitch_id` des Vereins. Regel-Änderungen wirken
  beim nächsten Lauf auch auf bestehende, nicht manuell zugeordnete
  ZUKÜNFTIGE Spiele (eigenes Update-Event trotz unverändertem sync_hash,
  wenn der Soll-Platz vom gespeicherten abweicht); vergangene Spiele werden
  nie umgehängt. Unsichere Zuordnungen erscheinen in der
  Verfügbarkeitsansicht als Hinweis-Layer „Heimspiel, Platz offen" – nie
  stillschweigend „frei".
- Fehler pro Quelle isolieren; Fehlertext in import_source, Anzeige im Admin.

## 7. Anzeigemodi, Farben, Filter

- `GET /api/events?von=&bis=&typ=&team=&bereich=&venue=` liefert IMMER beide
  Farbfelder (`team_farbe`, `venue_farbe`) + `venue_id`, zusätzlich
  `pitch_farbe` und `pitch_kuerzel` (beide NULL ohne zugeordneten Platz,
  z. B. Auswärtsspiel). Auch `/api/verfuegbarkeit` und das Offline-Bundle
  liefern Platzfarbe und -kürzel mit. Moduswechsel ist reines Frontend ohne
  neuen Request. `typ=belegung` liefert zusätzlich Heimspiele mit
  zugeordnetem Platz (Status ≠ abgesagt); sie erscheinen in der
  Platzbelegung auf ihrem Platz. Spiele tragen `manuell` (true =
  `import_source_id IS NULL`) und ein effektives `ende` (explizite Spalte,
  sonst Anstoß + 2 Std.).
- Platzfilter (`filter-pitch`, clientseitig, `/api/events` kennt ihn nicht):
  in der Platzbelegung unterhalb der Desktop-Sidebar-Schwelle (~1100 px)
  ersetzt er die Platz-Spalten; im Spielplan gilt er unabhängig von der
  Bildschirmbreite (kein Ressourcen-View dort). Ein gewählter Einzelplatz
  zeigt nur dessen Termine; „Alle Plätze" färbt nach Platzfarbe mit
  Platz-Kürzel (Fallback Platzname) als Text-Präfix vor dem Titel;
  Auswärtsspiele (nie eine `pitch_id`) bilden dabei die eigene Gruppe
  „Auswärts" mit der globalen Auswärtsfarbe.
- Filter „manuelle Termine" (`filter-manuell`, dreistufig: Alle / Ohne
  manuelle / Nur manuelle): clientseitig wie der Platzfilter, `/api/events`
  kennt ihn nicht; er wirkt auf das `manuell`-Flag im Event-Payload und
  funktioniert dadurch offline identisch. „Nur manuelle" blendet in der
  Platzbelegung auch Trainings/Sperrungen aus (Label macht das klar).
- Spielstätten-Auflösung zur **Anzeigezeit** im einen `VenueMatcher`-Service
  (Anzeige UND Import): erster `venue_begriff` nach sortierung,
  case-insensitive in `ort_text` → venue + Farbe; kein Treffer, aber Platz
  zugeordnet → Venue des Platzes (ein Spiel auf einem Platz ist per
  Definition an dessen Spielstätte, z. B. manuelles Spiel mit leerem
  ort_text); sonst → auswärts (Setting-Farbe).
- Filter: `team=<id>`, `bereich=<id>` (numerische Bereichs-ID; alte geteilte
  Links mit dem früheren Enum-String G/F/E/D/C/Herren funktionieren
  übergangsweise weiter – aufgelöst über `bereich.kuerzel`, Issue #27),
  `venue=<id>`, `venue=heim`, `venue=auswaerts`. Mehr-Team-Slots matchen,
  wenn EIN Team den Filter erfüllt; API liefert `team_ids` zusätzlich zu
  `team_id` (= erstes Team, bestimmt Farbe).
- Vereinssicht „Bei uns" = voreingestellte Filterkombination (Heimspiele +
  Belegungen + Restriktionen je Verein), keine eigene Datenlogik.
- Teamfarben als CSS-Variablen aus der DB (`:root { --team-<id>: … }`).

## 8. Frontend / UI

- Handgeschriebenes Stylesheet (Custom Properties, Grid, clamp(),
  `prefers-color-scheme`); mobile-first, Breakpoints ~768/~1100 px.
- **FullCalendar**: Desktop timeGridWeek/dayGridMonth, mobil eine eigene
  Listen-View (`listNachlade`, Basistyp `list`); Platzbelegung über Premium
  Resource-Views, Lizenzkey `GPL-My-Project-Is-Open-Source` (Projekt ist
  GPLv3).
- **Terminliste mit Nachladen**: `listNachlade` ist auf Mobilgeräten die
  Default-Ansicht von Platzbelegung UND Spielplan (nicht nur ein optionaler
  Modus); ihr sichtbarer Bereich beginnt deshalb am Wochenanfang (Montag) der
  laufenden Woche, nicht bei „heute" – sonst fehlten beim Öffnen bereits
  vergangene Tage der aktuellen Woche (Issue #26). Sie zeigt initial
  mindestens den kompletten nächsten Monat und lädt beim Scrollen ans
  Listenende weitere Batches nach (`von`/`bis` wächst schrittweise, die API
  kennt keine Pagination). Client-seitiger Cache dedupliziert nach
  Event-`id` (spätester Stand gewinnt, z. B. bei einer verlegten Partie);
  aktive Filter setzen Cache und Bereich auf den initialen Monat zurück.
  Reine Frontend-Logik (`public/js/nachlade.js`, unit-getestet mit
  `node --test tests/js`).
- Mobile-Patterns: Bottom-Sheets, Chip-Filter, Segmented Control,
  Touch-Ziele ≥ 44 px.
- **PWA/Offline**: Service Worker cached App-Shell; `GET /api/offline-bundle`
  (format-versioniert, aktuell 3 – Issue #27 hat eine `bereiche`-Liste sowie
  `team.bereich_id` ergänzt) liefert den **kompletten Datenbestand**
  (Issue #25): alle Spiele und Sperrungen bereits serialisiert (Feed-Shape,
  inkl. Platz-/Vereinszuordnung über `VenueMatcher`/`MatchDuration`),
  Trainings-Slots dagegen als **Regeln** (nicht expandiert) plus ihre
  Ausnahmen, dazu Teams (mit bereich_id)/Bereiche/Spielstätten/Plätze/
  Farben/Settings. Alle vier
  öffentlichen Ansichten – Spielplan, Platzbelegung, Terminliste UND
  Verfügbarkeit – müssen offline vollständig funktionieren, nicht nur für
  ein Zeitfenster: `public/js/offline-events.js` expandiert Slots
  clientseitig (Port von `SlotExpander`) und baut das `/api/events`-Shape;
  `public/js/offline-verfuegbarkeit.js` berechnet die Verfügbarkeit
  clientseitig (Port von `AvailabilityCalculator`) inkl. Nutzungszeiten,
  Prioritäten und Hinweis-Layer. Serverseitig sind `EventSerializer`
  (Spiel-/Sperrungs-Serialisierung) und `AvailabilityCalculator`
  (Timeline-Berechnung) als reine, DB-freie Klassen ausgelagert, damit
  dieselben goldenen Fixtures (`tests/fixtures/parity/`) PHP-Referenz UND
  JS-Port paritätsgetestet gegeneinander prüfen (Abschnitt 11). Ablage in
  IndexedDB mit Zeitstempel, Aktualisierung bei jedem Online-Besuch. Offline:
  Banner „⚠ Offline – Stand: <Zeit>" (Pflicht, prominent, mit
  Verlegungsrisiko-Hinweis, da nun auch weit entfernte Termine offline
  sichtbar sind), **kein** Zeitfenster-Hinweis mehr, **Schreiben gesperrt**.
  Ein Bundle mit veraltetem `format` gilt als „keine Daten" und wird beim
  nächsten Online-Besuch ersetzt.
- **Web-Push**: Kategorien Platzrestriktion (beide Arten) und
  Spielverlegung/-absage, je Team filterbar; Opt-in nur per Klick.
  push_subscription + notification_queue (mit ausgeloest_von_event_id);
  Queue wird als Event-Log-Konsument im Schreibpfad befüllt, Versand im
  Cron nach dem Import via `minishlink/web-push` (VAPID in shared/);
  404/410 → Subscription löschen. iOS-Hinweis: erst nach „Zum Homescreen".
- **Verfügbarkeitsansicht**: `GET /api/verfuegbarkeit?von=&bis=` – je Platz
  Intervalle frei|belegt|eingeschraenkt|gesperrt inkl. Grund, gruppiert nach
  Heimverein mit Adressen; freie Lücken nur innerhalb Setting
  **Nutzungszeiten**; 'eingeschraenkt' als Grund-Layer hinter Belegungen;
  Hinweis-Layer für Heimspiele ohne sichere Platz-Zuordnung.
- **Kalender-Abos**: stabile Feeds `/export/team/<id>.ics`,
  `/export/spiele.ics`, `/export/platz/<id>.ics`; **stabile UIDs aus
  aggregat_id** (Verlegung verschiebt statt dupliziert);
  `Content-Type: text/calendar; charset=utf-8`,
  REFRESH-INTERVAL/X-PUBLISHED-TTL. Abonnieren-Seite mit webcal://-Link,
  Google-Link (`calendar.google.com/calendar/r?cid=<feed-url>`), URL-Kopie
  für Outlook + Hinweis: Abos aktualisieren langsam, Push ist der schnelle
  Kanal. Feed-Abrufe → usage_stat.
- **Rechtsseiten**: Impressum + Datenschutzerklärung admin-editierbar
  (Markdown in setting/page), Footer-Links überall. Inhalt der
  Datenschutzerklärung: IP im Event-Log (90-Tage-Anonymisierung), Web-Push,
  Hosting all-inkl.
- **Farbe ist nie das einzige Signal**: Events tragen immer Text
  (Team-Kürzel/Ortsname), Zustände immer ein Label. Abnahmekriterium.
- Cache-Busting: Assets `?v=<VERSION>`, SW-Cache-Name enthält Version.
- **Vereinswappen**: Admin-Upload (nur PNG – GD kann kein SVG rastern, einzige
  auf Shared Hosting garantiert vorhandene Bild-Erweiterung), Ablage in
  `shared/var/wappen/` (überlebt Updates, Teil von Backup-ZIP und Restore,
  Abschnitt 9). Größen (Favicon 16/32, Apple-Touch-Icon 180, PWA-Icon
  192/512 maskable, Logo 256) werden per GD beim Upload einmalig abgeleitet,
  nicht bei jedem Request; Cache-Busting über `?v=<Datei-mtime>`, kein
  DB-Zugriff nötig. `manifest.webmanifest` wird dynamisch per PHP-Route
  ausgeliefert (Icons zeigen bei vorhandenem Wappen auf die Ableitungen,
  sonst auf den neutralen SVG-Platzhalter `icon.svg`, der auch als Logo-
  Fallback in der Kopfzeile dient). Hinweis im Admin: bereits installierte
  PWAs übernehmen ein neues Icon erst bei Neuinstallation (Plattform-
  Verhalten). Dateiname/Zeitstempel des Uploads landen als Setting, die
  Bilddatei selbst nie im Event-Log (Abschnitt 3).

## 9. Self-Update, Backup, Installer

Update = Schrittkette (AJAX, `shared/update_state.json`, jeder Schritt
idempotent, < 30 s):

1. **Versionscheck**: GitHub Releases API, URL fest aufs eigene Repo.
   **Update-Kanal** (Setting): 'stable' = `/releases/latest`
   (ignoriert Pre-Releases), 'beta' = neuestes aus `/releases` inkl.
   Pre-Releases. Testinstanz läuft auf 'beta'.
2. **Backup**: DB-Dump in reinem PHP; ZIP aus dump.sql + config.php +
   manifest.json (Version, Zeitstempel) nach shared/var/backups/. Auch
   manuell per Admin-Button.
3. **Download + Entpacken** nach releases/vX.Y.Z/, SHA-256 gegen
   checksums.txt; laufende Version wird nie berührt.
4. **Umschalten**: maintenance.flag → rename(current, releases/_prev) →
   rename(neu, current) → Flag weg. Zustand ist nach Absturz aus dem
   Dateisystem rekonstruierbar; Schrittkette repariert beim nächsten Aufruf.
5. **Migrationen**: alle NNN_*.sql > schema_version einspielen. Migrationen
   sind **eine Version rückwärtskompatibel** (hinzufügen ja;
   umbenennen/löschen erst in der Folgeversion). Bei Fehler: Wartungsmodus
   bleibt, Admin wählt Rollback oder Wiederholen.
6. **Abschluss**: Selbsttest (Startseite + /api/events → 200), die letzten
   2 Releases behalten.

**Rollback** = _prev zurück-renamen (Code läuft dank kompatibler Migrationen
auf neuem Schema); Daten-Rollback nur über Backup-Restore.

**setup.php** (Nextcloud-Stil, eigenes Release-Asset): Umgebungs-Checkliste
(PHP-Version, ZipArchive, Schreibrechte, rename, HTTPS) → Release
laden/prüfen/entpacken, web/-Shim + shared/ anlegen → `/install`:
DB-Zugangsdaten + Test, dann frische Installation ODER Backup einspielen
(dump.sql häppchenweise mit Offset in Statusdatei; danach nur Migrationen >
Backup-Stand) → config.php schreiben (sperrt Installer) → setup.php löscht
sich selbst. `/install` nur erreichbar solange config.php fehlt.
Download/Prüf/Entpack-Code von setup.php und Updater ist derselbe.

## 10. Release-Prozess

- Je Version: Tag als **Pre-Release** → Testinstanz (beta) prüft →
  Release auf „latest" umstellen → Produktivinstanz (stable) zieht nach.
- GitHub Action bei Tag vX.Y.Z: `composer install --no-dev
  --optimize-autoloader`, VERSION schreiben, ZIP (app/, public/, vendor/,
  bin/, migrations/, VERSION) + checksums.txt (SHA-256) + setup.php als
  Release-Assets.
- Lizenz GPLv3 (Voraussetzung für den FullCalendar-Key).

## 11. Entwicklungs-Konventionen

- Lokal: docker-compose mit PHP 8.5 + MySQL, all-inkl-nah
  (disable_functions=exec,shell_exec,…; realistische Limits); App läuft per
  `docker compose up`.
- CI unter PHP 8.5 mit `error_reporting(E_ALL)`; Deprecations = Fehler.
  Fremdbibliotheken vor Aufnahme auf PHP-8.5-Kompatibilität prüfen.
- **Pflicht-Testabdeckung (PHPUnit)**: Event-Schreibpfad (Events + Projektion
  in einer Transaktion); Replay-Determinismus (Replay ⇒ Projektionen
  identisch zum Live-Stand); Ausschluss-Szenario (Events einer IP
  ausschließen → Rebuild → Änderungen weg, Fremd-Events intakt, Verwaiste im
  Report); Korrektur-Events; **Payload-Normalisierung alter Event-Formate**
  (Alt-Event team_id/wochentag → Listenformat, deterministisch);
  Slot-Expansion inkl. mehrerer Teams und Wochentage, Ausnahmen,
  Restriktionen; **alle drei Bearbeitungs-Umfänge** (alle / ab hier mit
  atomarem Split / nur dieser) inkl. Verhalten bei Transaktionsabbruch;
  ICS-Sync (insert/update/skip/abgesagt, Verlegung per gleicher UID);
  VenueMatcher (Mehrfach-Begriffe, Priorität, case-insensitive, heim vs.
  auswärts); Konfliktprüfung (gesperrt blockiert, eingeschraenkt warnt);
  Verfügbarkeitsberechnung (Lücken innerhalb Nutzungszeiten);
  Zeitumstellungs-Tests (Slot-Expansion + ICS-Export über beide
  DST-Wochenenden); Migrationslauf von 0; Backup + Restore-Roundtrip;
  Bootstrap-Admin-Regel; Heimspielstätten-Regeln (Zuordnungs-Priorität
  manuell > Regel > Standard, Grenztag-Anstöße inklusive, Reflow nur für
  zukünftige nicht manuell zugeordnete Spiele, pitch_manuell-Upcasting
  beim Replay); manuelle Spiele (Schreibpfad create/update/delete inkl.
  Guards gegen Import-Spiele, Konfliktprüfung beim Spiel-Anlegen: gesperrt
  blockiert / Überlappung + eingeschraenkt warnen, **„Import ignoriert
  manuelle Spiele"-Regression**, ende-Fallback in Konfliktprüfung/
  Verfügbarkeit/Feed/Export, ende-NULL-Upcasting beim Replay, Push bei
  Verlegung/Absage manueller Spiele, kein Push bei Löschung); **Bereich-
  Upcasting** (Issue #27: Alt-Team-Event mit nur dem Enum-String → über die
  System-Seed-Events im Event-Log auf die passende bereich_id gehoben,
  deterministisch unabhängig von der Replay-Reihenfolge relativ zu den
  Seed-Events, unverändert auch nach Umbenennung des Bereichs); Bereiche-
  CRUD (Delete-Guard bei referenzierenden Teams, Deaktivieren statt Löschen,
  ein bereits zugewiesener – nun inaktiver – Bereich bleibt für das eigene
  Team gültig, neue Zuweisungen an ihn werden abgelehnt); Drag&Drop-
  Sortierung (nur tatsächlich verschobene Zeilen erhalten ein Updated-Event,
  eine Transaktion); **Offline-
  Paritätstests** (Issue #25): goldene Fixtures
  (`tests/fixtures/parity/bundle.json` + `cases.json`, inkl. beider
  DST-Wochenenden, überlappender Slots, mehrtägiger vor dem Zeitraum
  beginnender Sperrung) prüfen, dass die clientseitigen Ports
  (`public/js/offline-events.js`, `public/js/offline-verfuegbarkeit.js`)
  byte-identisch zur PHP-Referenz (`SlotExpander`/`EventSerializer`,
  `AvailabilityCalculator`) sind – `tests/Kalender/ParityFixturesTest.php`
  (PHPUnit, DB-frei) und `tests/js/offline-*.test.js` (`node --test
  tests/js`, Teil der CI) laufen gegen dieselben committeten
  `expected/*.json`; bei Algorithmus-Änderungen `generate.php` neu laufen
  lassen und den Diff bewusst reviewen.
- Konfliktprüfung im `BookingService`; DIESELBE Expansionslogik speist die
  Verfügbarkeitsansicht.
- **Zeitzonen**: durchgängig `Europe/Berlin` (zentral im Bootstrap gesetzt,
  DATETIME = lokale Zeit); ICS-TZID beim Import konvertieren.
- SQL nur über Repositories mit Prepared Statements; kein
  Query-Builder-Framework. Ausgaben escapen; JSON mit korrekten Headern.
- UI-Texte Deutsch; Code/Kommentare/Commits Englisch.
- Jede Schema-Änderung = neue nummerierte Migration; alte nie ändern.
- Architektur- oder Datenmodell-Änderungen aktualisieren diese Datei im
  selben PR.
- Vor jedem Merge: Tests grün + `docker compose up` + Smoke-Test des
  setup.php-Flows im frischen Container.
