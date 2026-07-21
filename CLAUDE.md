# CLAUDE.md – Vereinskalender

Webkalender für einen Fußballverein (Spielgemeinschaft): Sportplatz-Belegung
(Training), Spielplan (ICS-Import), Verfügbarkeit. Die Anwendung ist
implementiert und in Betrieb; dieses Dokument ist die verbindliche
Architektur-Referenz. Bei Widersprüchen zwischen Code und Dokument gilt das
Dokument – oder es wird hier im selben PR bewusst geändert.
Änderungswünsche werden als GitHub Issues geführt, nicht in dieser Datei.

## 1. Harte Umgebungs-Constraints (niemals verletzen)

- Shared Hosting bei shared hosting: **kein SSH, kein Git, kein Composer, kein
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
- **pitch**: venue_id FK (Heimverein), sportheim_id FK NULL (Issue #36: nicht
  jeder Platz liegt an einem Sportheim), name, kuerzel (Pflichtfeld, max. 10
  Zeichen, für die Text-Beschriftung bei der Platz-Gruppierung im
  Kalender), farbe (aus vordefinierter Palette), typ, flutlicht, adresse
  NULL (nur falls abweichend), sortierung. Alt-Events ohne Farbe bzw. ohne
  Kürzel (vor Einführung der jeweiligen Spalte) werden beim Replay
  deterministisch auf eine feste Default-Farbe bzw. ein leeres Kürzel
  gehoben (Upcasting, analog training_slot); Alt-Events ohne sportheim_id
  werden analog auf NULL gehoben (Migration 014). Das Frontend fällt bei
  leerem Kürzel auf den Platznamen zurück.
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
- **sportheim** (Issue #36): venue_id FK (Heimverein), name, adresse NULL
  (nur falls abweichend), sortierung, aktiv. Eigenes Event-Aggregat wie
  `bereich`/`venue`. Löschen nur ohne referenzierende Räume, Plätze und
  Vermietungen (sonst deaktivieren – Historie bleibt); inaktive Sportheime
  verschwinden aus Filtern/Neuanlagen.
- **sportheim_raum** (Issue #36): sportheim_id FK, name (z. B. „Gastraum",
  „Kegelbahn"), kuerzel, sortierung, aktiv. Mehrere Räume je Sportheim.
  Löschen nur ohne referenzierende Vermietungen (sonst deaktivieren).
- **vermietung** (Issue #36): sportheim_id FK, raum_ids (Liste 0..n – leer =
  gesamtes Sportheim), von, bis (DATETIME), titel (Anlass), kontakt NULL
  (Freitext), bemerkung NULL. **Blockiert nie** Trainings oder Spiele –
  `BookingService` behandelt eine überlappende Vermietung ausschließlich als
  Hinweis (`ConflictCheckResult::$hinweise`, nie `$conflicts`/`$warnings`),
  nicht als Konflikt oder bestätigungspflichtige Warnung. Anlegen/
  Bearbeiten/Löschen öffentlich (Ebene 2) als Events wie manuelle Spiele,
  Löschen = delete-Event; keine Konfliktprüfung beim eigenen Schreiben.
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
bleibt als Fallback), Sportheime-CRUD (Issue #36, inkl. eingebetteter
Räume-Verwaltung analog Spielstätten-Begriffe, Drag&Drop-Sortierung je Liste,
Delete-Guard bei referenzierenden Plätzen/Räumen/Vermietungen → deaktivieren
statt löschen), Import-Quellen, Event-Historie (Filter:
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

- Hoster-Cronjob ruft `bin/import_ics.php` alle 10 Min per HTTP auf
  (Secret-Token aus config.php).
- Sync pro Event über `(import_source_id, ics_uid)`: unbekannt → INSERT;
  bekannt + sync_hash geändert → UPDATE (Verlegung: UID bleibt,
  DTSTART/SEQUENCE ändern sich); unverändert → skip. Nachlauf: im Feed
  fehlende UIDs → `status='abgesagt'`, NIEMALS hart löschen – **beschränkt
  auf die Zukunft** (Issue #48): abgesagt wird nur ein Spiel, dessen Anstoß
  NACH dem Importzeitpunkt (`Europe/Berlin`) liegt; bereits begonnene bzw.
  vergangene Spiele (Grenzfall Anstoß = Importzeitpunkt zählt als
  „begonnen“) rührt der Nachlauf nie an, unabhängig davon, ob ihre UID noch
  im Feed steht – manche Feeds lassen vergangene Termine fallen, das ist
  keine echte Absage. Anstoß statt Anstoß+`MatchDuration`, da für
  Import-Spiele nur ein geschätztes Ende existiert (Fallback in
  `MatchDuration`) und eine destruktive Statusänderung nicht an eine
  Schätzung gekoppelt werden soll; ein laufendes Spiel bleibt so in jedem
  Fall unangetastet. Das reguläre Insert/Update-Verhalten ist davon
  unberührt: ein vergangenes Spiel mit geändertem `sync_hash` wird
  weiterhin aktualisiert, nur das automatische Absagen ist auf die Zukunft
  beschränkt.
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
  liefern Platzfarbe und -kürzel mit. **Jeder Termin (außer Sperrungen)
  zeigt Team- UND Spielstättenfarbe gleichzeitig als zwei Farbpunkte** vor
  dem Titel, in jeder Ansicht und Breite inkl. Terminliste (Issue #39,
  ersetzt den früheren Team/Spielstätte-Umschalter; kein neuer Request, da
  beide Farbfelder bereits im Event-Payload liegen) – bei Auswärtsspielen
  liefert `venue_farbe` bereits die Auswärtsfarbe, kein Sonderfall im
  Frontend nötig. Sperrungen haben kein Team und bleiben bei ihrer
  bestehenden Art-Farbe (gesperrt/eingeschränkt). `typ=belegung` liefert
  zusätzlich Heimspiele mit zugeordnetem Platz (Status ≠ abgesagt); sie
  erscheinen dort auf ihrem Platz. Spiele tragen `manuell`
  (true = `import_source_id IS NULL`) und ein effektives `ende` (explizite
  Spalte, sonst Anstoß + 2 Std.). Die zusammengeführte Kalenderseite (Issue
  #37, Abschnitt 8) fragt `typ=''` ab (alle Termintypen in einem Feed, ohne
  Duplikate); `typ=belegung`/`typ=spiel` bleiben als engere API-Filterwerte
  Teil der öffentlichen Schnittstelle, werden vom Frontend aber nicht mehr
  gesendet.
- Die Antwort ist `{ events, naechster }` (Issue #52): `naechster` ist das
  Datum des nächsten Termins **nach `bis`**, oder `null`, wenn danach
  nachweislich keiner mehr folgt. Es trägt allein die Abbruchbedingung der
  Terminliste (Abschnitt 8) – die Grid-Ansichten ignorieren es. Bewusst eine
  **untere Schranke**, keine exakte Auskunft: nie SPÄTER als der echte
  nächste Termin, `null` nur bei wirklich leerem Rest. Zwei Abschwächungen
  fallen darunter: Trainings-Slots liefern die erste passende
  Wochentags-Occurrence ihrer Regel **ohne** `slot_exception`-Abgleich
  (Ausnahmen können Termine nur entfernen), und die Filter
  `team`/`bereich`/`venue` bleiben unberücksichtigt (gefilterte Termine sind
  eine Teilmenge; `venue` ist in SQL ohnehin nicht auflösbar, der
  `VenueMatcher` arbeitet zur Anzeigezeit). Eine zu frühe Schranke kostet
  einen leeren Batch-Request, eine zu späte würde Termine verschlucken –
  deshalb die Asymmetrie. Berechnung in `EventFeedService::naechsterTermin()`
  (MIN-Abfragen je Quelle + `NextEventDate` für die Slot-Regeln).
- Platzfilter (`filter-pitch`, clientseitig, `/api/events` kennt ihn nicht):
  immer sichtbar (Issue #37). In den Ressourcen-Views (Tag/Woche, ab der
  Desktop-Sidebar-Schwelle ~1100 px) reduziert ein gewählter Einzelplatz die
  Platz-SPALTEN auf genau diesen Platz (inkl. der synthetischen „Auswärts"-
  Spalte für Spiele ohne `pitch_id`); in jeder anderen Kombination (Monat,
  Liste, schmale Tag-/Wochenansicht) filtert er stattdessen die Termine
  direkt. Ohne Einzelplatz-Auswahl trägt „Alle Plätze" die Platzfarbe als
  Ersatz für die fehlenden Spalten an den Termin – mit Platz-Kürzel (Fallback
  Platzname) als Text-Präfix vor dem Titel; Auswärtsspiele (nie eine
  `pitch_id`) bilden dabei die eigene Gruppe „Auswärts" mit der globalen
  Auswärtsfarbe. **Wie** die Farbe erscheint, hängt an der Darstellung
  (Issue #57, eine Entscheidungsstelle:
  `VKKalenderPitch.platzFarbDarstellung(modus, hatResourceSpalten, pitchFilter)`):
  Tag/Woche ohne Ressourcen-Spalten färben den Termin-HINTERGRUND; der Monat
  bekommt stattdessen einen dritten Farbpunkt (Quadrat wie der
  Spielstätten-Punkt, Legende Issue #38), weil `dayGridMonth` zeitgebundene
  Termine als Dot-Events ohne Block-Fläche rendert – ein Hintergrund kommt
  dort nicht an, und der eigene `eventContent` ersetzt zudem FullCalendars
  eigenen Punkt. Die Terminliste (`listNachlade`, per Umschalter jederzeit
  erreichbar, Issue #37) ist kein Ressourcen-Ersatz, sondern ein
  chronologischer Feed: dort bleibt der Hintergrund neutral (Issue #40) – die
  Team-/Spielstättenfarbe zeigen dort wie überall die zwei Farbpunkte,
  unabhängig von „Alle Plätze"; der Platz-Kürzel-Präfix im Titel bleibt in
  allen Darstellungen unberührt.
- **Alles Darstellungsabhängige wird beim RENDERN abgeleitet, nie im
  Event-Datensatz gespeichert** (Issue #57, Invariante): Platzfarbe und
  Platz-Präfix entstehen in `eventContent`/`eventDidMount` aus dem aktuellen
  `modus`, der live gelesenen Breite (`istBreit()`, kein Snapshot mehr) und
  dem Platzfilter. Grund: `setzeModus()` wechselt nur die View, und
  FullCalendar fetcht mit `lazyFetching` ausschließlich nach, wenn die neue
  Range über die geladene hinausgeht – jeder Wechsel in eine ENGERE Range
  (Monat→Woche, Monat→Tag, Woche→Tag, Liste→alles) benutzt die gecachten
  Event-Objekte weiter. Vorberechnete Werte im Datensatz überleben den
  Wechsel damit und zeigen die Regel der VORHERIGEN Darstellung. Aus
  demselben Grund liefert `aktuelleRessourcen()` die Spaltenliste
  breitenunabhängig (eine einmal leer gecachte Liste ließ eine später
  aktivierte Ressourcen-View sonst alle Events lautlos verwerfen). Im
  Datensatz darf nur stehen, was allein am Termin hängt – die Art-Farbe der
  Sperrungen.
- Filter „manuelle Termine" (`filter-manuell`, dreistufig: Alle / Ohne
  manuelle / Nur manuelle): clientseitig wie der Platzfilter, `/api/events`
  kennt ihn nicht; er wirkt auf das `manuell`-Flag im Event-Payload und
  funktioniert dadurch offline identisch. „Nur manuelle" blendet dabei auch
  Trainings/Sperrungen aus (Label macht das klar).
- **Vermietungen** (Issue #36) sind ein eigener Termintyp (`typ=vermietung`),
  ausschließlich im zusammengeführten Feed (`typ=''`) enthalten – nie unter
  `typ=belegung`/`typ=spiel`; ein aktiver Team-/Bereichsfilter blendet sie
  aus (kein Team), ein Venue-Filter matcht über die Spielstätte des
  Sportheims. Payload trägt `sportheim_id`, `sportheim_name`, `raum_ids`,
  `raum_text` (Kürzelliste, leer → „gesamtes Sportheim"), `kontakt`,
  `bemerkung`, kein `team_id`/`pitch_id`. Trainings/Belegungen/Sperrungen/
  Spiele tragen zusätzlich `pitch_sportheim_id` (NULL ohne Sportheim-
  Zuordnung des Platzes), damit der Client Termine ohne Zusatz-Request gegen
  laufende Vermietungen abgleichen kann (Hinweis-Indikator, Abschnitt 8).
  Filter „Vermietungen" (`filter-vermietung`, dreistufig wie `filter-manuell`):
  clientseitig, `/api/events` kennt ihn nicht; „Nur Vermietungen" blendet
  auch Trainings/Spiele/Sperrungen aus.
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
- **FullCalendar** (Issue #37): EINE öffentliche Kalenderseite (`/kalender`;
  `/belegung` und `/spielplan` leiten per 301 inkl. Query-String dorthin um)
  mit vier Darstellungen **Tag/Woche/Monat/Liste** über eigene Umschalter-
  Buttons (`customButtons`, nicht FullCalendars eingebaute View-Buttons – der
  `events`-Callback feuert für eine neu aktivierte View, BEVOR
  `calendar.view.type` den Wechsel widerspiegelt; Rendering-Logik liest
  deshalb einen eigenen `modus`-State statt `calendar.view.type`). Default
  **Woche** (mobil, <768 px: **Tag** – eine 7-Spalten-Woche ist dort praktisch
  unlesbar, Liste bleibt einen Tap entfernt); die zuletzt gewählte
  Darstellung wird in `localStorage` (`kalender_ansicht`) gemerkt und je
  Wechsel in usage_stat gezählt (`feature_ansicht_tag/woche/monat/liste`,
  `StatController`-Whitelist). Ab der Desktop-Sidebar-Schwelle (~1100 px)
  zeigen Tag UND Woche Platz-Spalten (Premium Resource-Views, Lizenzkey
  `GPL-My-Project-Is-Open-Source`, Projekt ist GPLv3) inkl. einer
  synthetischen „Auswärts"-Spalte für Spiele ohne `pitch_id` sowie einer
  synthetischen „Sportheim"-Spalte (Issue #36) für Vermietungen (die keinen
  Platz, sondern ein Sportheim betreffen); Monat und Liste haben nie
  Spalten. Der Button „+ Eintragen" öffnet ein Auswahl-Sheet („Belegung
  eintragen" / „Spiel eintragen" / „Vermietung eintragen") statt getrennter
  Toolbar-Buttons. Vermietungen zeigen als Termin nur den
  Spielstätten-Farbpunkt (kein Team) mit Text-Label „Vermietung: <Anlass>
  (<Räume>)"; Trainings/Spiele auf einem Platz eines gerade vermieteten
  Sportheims tragen zusätzlich einen dezenten 🏠-Indikator, der volle
  Hinweis („Sportheim vermietet: <Anlass>, Nutzung ggf. eingeschränkt")
  steht im Detail-Dialog (`public/js/vermietung-hinweis.js`, reiner
  Overlap-Abgleich auf den bereits geladenen Events, kein Zusatz-Request).
- **Zeitraum-Anzeige** (Issue #53): steht neben der Überschrift „Kalender"
  (`#kalender-zeitraum`), NICHT mehr in FullCalendars eigener Toolbar –
  `headerToolbar` hat seit Issue #53 keinen `center`-Slot mehr. Grund: die
  Terminliste (Issue #31/#52) setzte den sichtbaren Bereich schon vorher
  manuell in `.fc-toolbar-title`, weil ihre View-Range technisch fix auf
  einen 15-Jahres-Horizont steht; ein direktes `textContent`-Schreiben in
  dasselbe, von FullCalendar (Preact-basiert) verwaltete Element ließ beim
  Wechsel weg von der Liste Preacts eigenen, korrekten Titel NEBEN dem
  extern gesetzten Rest stehen statt ihn zu ersetzen (zwei Text-Kindknoten
  im selben Element – der eigentliche Bug hinter Issue #53 Teil A). Die
  Anzeige ist deshalb ein von FullCalendar nie berührtes eigenes Element;
  `public/js/kalender-titel.js` liefert dafür reinen, DOM-freien Text je
  Darstellung (Tag/Monat einzelnes Datum, Woche/Liste ein Bereich, mobil
  kompaktes Zahlenformat wie „18.–24.07.2026"). Gespeist wird sie für
  Tag/Woche/Monat aus `datesSet` (NICHT aus dem `events`-Callback – dessen
  `info.start/info.end` ist bei Monat der gepolsterte 6-Wochen-Grid-Bereich,
  nicht der 1.–31.; `calendar.view.type` ist dort laut obigem Kommentar noch
  veraltet) über `info.view.currentStart/currentEnd` (die logischen,
  ungepolsterten View-Grenzen) plus den bereits vorhandenen `modus`-State;
  für die Liste weiterhin manuell aus dem geladenen Bereich
  (`listeGeladenBis`), gegen ein spätes Zurückschreiben eines noch
  laufenden Hintergrund-Batches nach einem Wechsel weg von der Liste
  bewacht durch denselben `listeAktiv`/`calendar.view.type`-Check wie zuvor.
  Feste `min-height` auf `#kalender-zeitraum` verhindert einen Layoutsprung
  beim Darstellungswechsel. FullCalendars eigene Toolbar
  (`.fc-header-toolbar`) bricht auf schmalen Viewports (360–430 px) sauber
  in zwei Zeilen um statt – wie zuvor – horizontal zu scrollen (Issue #3
  führte den Scroll-Ansatz ein, Issue #53 ersetzt ihn: der Titel-Slot war
  dort der größte Platzverbraucher, ohne ihn passen Navigation und die vier
  Umschalter-Buttons ohne Scrollbalken).
- **Terminliste mit Nachladen**: `listNachlade` ist eine der vier
  Darstellungen (Issue #37, per Umschalter erreichbar, nicht mehr an eine
  Ansicht/Bildschirmbreite gebunden); ihr sichtbarer Bereich beginnt beim
  Wechsel dorthin immer am Wochenanfang (Montag) der laufenden Woche, nicht
  bei „heute" – sonst fehlten beim ersten Öffnen bereits vergangene Tage der
  aktuellen Woche (Issue #26). Sie zeigt initial mindestens den kompletten
  nächsten Monat und lädt beim Scrollen ans Listenende weitere Batches nach
  (`von`/`bis` wächst schrittweise, die API kennt keine Pagination).
  Client-seitiger Cache dedupliziert nach Event-`id` (spätester Stand
  gewinnt, z. B. bei einer verlegten Partie); aktive Filter setzen Cache und
  Bereich auf den initialen Monat zurück. Reine Frontend-Logik
  (`public/js/nachlade.js`, unit-getestet mit `node --test tests/js`).
  **Abbruch und Lücken** (Issue #52): Das Ende der Kette wird NIE aus leeren
  Batches abgeleitet – maßgeblich ist allein `naechster` aus der
  Feed-Antwort (Abschnitt 7). `naechster === null` beendet die Kette und
  zeigt „keine weiteren Termine"; liegt `naechster` hinter dem geladenen
  Bereich (Winterpause), **springt** der nächste Batch direkt dorthin,
  statt sich in 31-Tage-Schritten durch die Lücke zu tasten. Eine beliebig
  lange Lücke kostet damit genau einen zusätzlichen Roundtrip – den leeren
  Batch, der `naechster` mitgebracht hat. Die frühere Heuristik („3 leere
  Batches in Folge = erschöpft") überbrückte nur 93 Tage und beendete die
  Liste mitten in einer längeren Pause. Nach einem leeren Batch lädt der
  mobile Scroll-Trigger weiterhin selbständig weiter (Issue #46: ein
  unveränderter Sentinel löst keinen neuen IntersectionObserver-Trigger
  aus) – das bleibt nötig, weil `naechster` nur eine untere Schranke ist.
- Mobile-Patterns: Bottom-Sheets, Chip-Filter, Segmented Control,
  Touch-Ziele ≥ 44 px.
- **Legende** (Issue #38): EINE Komponente für Spielstätten-, Platz- und
  Team-Kürzel/-Farben (Teams gruppiert nach Bereich, dazu die globale
  Auswärts-Farbe; nur aktive Bereiche/Teams). Serverseitig gibt es dafür
  keine eigene Route/kein eigenes Template mit Namen/Farben – `public/js/
  legende.js` füllt jeden `[data-legende]`-Container aus derselben
  `appData`, die auch Kalender-/Verfügbarkeitsansicht aus `#app-data` lesen
  (`PublicController::stammdaten()`); dadurch ist sie ohne dritte
  Datenpflege offline identisch verfügbar (die Seite inkl. eingebetteter
  appData wird vom Service Worker gecacht). Drei Einbindungen derselben
  Mounts: Startseite (`<details>`, einklappbar), eigene Route `/legende`
  (teilbar), Overlay-Dialog (`<dialog class="sheet legende-sheet">`, Button
  in der Kalender-Toolbar) mit Escape- und Klick-außerhalb-Schließen
  (Escape nativ, Klick außerhalb eigens verdrahtet – andere Dialoge der App
  bieten das bewusst nicht). Farbpunkte teilen sich die Kontrast-Technik
  der Termin-Punkte (Issue #39): Team = Kreis, Spielstätte/Platz = Quadrat,
  Sportheim/Raum = Raute (Issue #47, eigene Form – Sportheime haben noch
  keine eigene Farbe, daher die Farbe ihrer Spielstätte), Text immer
  daneben. Gruppe „Sportheime" (je Sportheim eingerückt seine Räume, nur
  aktive, in gepflegter `sortierung`) nutzt dieselbe `appData` wie
  Spielstätten/Plätze/Teams (`stammdaten()` liefert `sportheime`/
  `sportheimRaeume` bereits mit); ein Platz mit `sportheim_id` zeigt sein
  Sportheim zusätzlich als 🏠-Text in der Plätze-Gruppe. Ein
  Symbole-Abschnitt erklärt den 🏠-Indikator an Terminen (Sportheim gerade
  vermietet) und die Vermietungs-Darstellung (nur Spielstätten-Punkt, kein
  Team).
- **PWA/Offline**: Service Worker cached App-Shell; `GET /api/offline-bundle`
  (format-versioniert, aktuell 4 – Issue #36 hat `sportheime`/
  `sportheim_raeume`/`vermietungen`-Listen ergänzt und `pitch.sportheim_id`
  aufgenommen) liefert den **kompletten Datenbestand**
  (Issue #25): alle Spiele, Sperrungen und Vermietungen bereits serialisiert
  (Feed-Shape, inkl. Platz-/Vereinszuordnung über
  `VenueMatcher`/`MatchDuration`), Trainings-Slots dagegen als **Regeln**
  (nicht expandiert) plus ihre Ausnahmen, dazu Teams (mit bereich_id)/
  Bereiche/Spielstätten/Plätze (mit sportheim_id)/Sportheime/Räume/Farben/
  Settings. Die Kalenderseite (alle vier Darstellungen Tag/Woche/
  Monat/Liste, Issue #37) UND die Verfügbarkeit müssen offline vollständig
  funktionieren, nicht nur für ein Zeitfenster: `public/js/offline-events.js`
  expandiert Slots
  clientseitig (Port von `SlotExpander`) und baut das `/api/events`-Shape
  (inkl. Vermietungen, überlappend zum abgefragten Zeitraum);
  `public/js/offline-verfuegbarkeit.js` berechnet die Verfügbarkeit
  clientseitig (Port von `AvailabilityCalculator`) inkl. Nutzungszeiten,
  Prioritäten, Hinweis-Layer und dem Vermietungs-Hinweis-Layer je
  Spielstätte. Serverseitig sind `EventSerializer`
  (Spiel-/Sperrungs-Serialisierung) und `AvailabilityCalculator`
  (Timeline-Berechnung) als reine, DB-freie Klassen ausgelagert, damit
  dieselben goldenen Fixtures (`tests/fixtures/parity/`) PHP-Referenz UND
  JS-Port paritätsgetestet gegeneinander prüfen (Abschnitt 11).
  `VKOfflineEvents.naechsterTermin()` liefert zusätzlich das `naechster`-Feld
  (Issue #52, Abschnitt 7) aus demselben Bundle, damit die Terminliste
  offline **dieselbe** Abbruchbedingung hat wie online – kein Sonderweg,
  kein Zusatz-Request, keine `format`-Erhöhung (das Bundle enthält bereits
  alles Nötige). Bewusst NICHT paritätsgetestet: serverseitig sind es
  MIN-Abfragen, clientseitig ein Array-Scan; verbindlich ist nur die
  Schranken-Eigenschaft (Abschnitt 7), eine Abweichung kostet höchstens
  einen leeren Batch-Request. Ablage in
  IndexedDB mit Zeitstempel, Aktualisierung bei jedem Online-Besuch. Offline:
  Banner „⚠ Offline – Stand: <Zeit>" (Pflicht, prominent, mit
  Verlegungsrisiko-Hinweis, da nun auch weit entfernte Termine offline
  sichtbar sind), **kein** Zeitfenster-Hinweis mehr, **Schreiben gesperrt**.
  Ein Bundle mit veraltetem `format` gilt als „keine Daten" und wird beim
  nächsten Online-Besuch ersetzt. `manifest.webmanifest`-`start_url` zeigt auf
  `/kalender`; der Service Worker cached nur noch `/kalender` (nicht mehr die
  Alt-Routen) und mappt `/belegung`/`/spielplan` offline auf die gecachte
  Kalenderseite, damit alte Bookmarks funktionieren.
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
  Hinweis-Layer für Heimspiele ohne sichere Platz-Zuordnung. Vermietungen
  (Issue #36) erscheinen als eigener, je Spielstätte gruppierter
  Hinweis-Layer (`venue.vermietungen`) – der Platz bleibt dabei
  frei/belegt wie sonst auch, wird NIE als gesperrt gewertet.
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
  Hosting beim Shared-Hosting-Anbieter.
- **Farbe ist nie das einzige Signal**: Events tragen immer Text
  (Team-Kürzel/Ortsname), Zustände immer ein Label. Abnahmekriterium.
- **Termininhalte werden beschnitten, nie überlaufen** (Issue #58): bei
  geteilter Spaltenbreite (mehrere zeitgleiche Termine in Tag/Woche) oder
  engen Monats-/Listenzellen reicht der Platz oft nicht für Farbpunkte,
  Uhrzeit, Platz-Kürzel-Präfix und Titel gleichzeitig. Kürzungsreihenfolge:
  Farbpunkte bleiben immer sichtbar (`.ev-punkte`, `flex-shrink:0` -
  kompaktestes Signal), Titel und Präfix teilen sich den Rest über
  `flex-shrink: 9999` (Titel) vs. `1` (Präfix) statt eines gleichmäßigen
  Verhältnisses - der Titel gibt praktisch die gesamte Schrumpfung zuerst
  her (bis Ellipsis bei 0 ankommt), erst danach greift überhaupt etwas vom
  Präfix. `container-type: inline-size` + `@container` (naheliegender erster
  Versuch, um den Präfix unterhalb einer festen Breite ganz auszublenden)
  scheitert an einer Flexbox-Containment-Falle: ein size-contained Flex-Kind
  mit `flex-grow` ignoriert dabei die tatsächlich verfügbare Breite und
  bleibt bei 0 hängen, obwohl Platz da wäre - reproduziert im Monatsraster
  (Event 211px breit, Titel blieb trotzdem bei 0px). Zweite, subtilere
  Ursache des ursprünglich gemeldeten Überlaufs: FullCalendars eigenes
  `.fc-v-event .fc-event-main-frame{flex-direction:column}` (zwei Klassen,
  höhere Spezifität als unser einklassiges `.ev-inhalt`) kippte die
  Content-Zeile in Tag/Woche in eine SPALTE - ohne horizontale Haupt-Achse
  schrumpft dort nichts mehr, jedes Kind nimmt seine natürliche Breite und
  ragt über den Block hinaus, unabhängig von jeder Ellipsis-Regel auf den
  Kindern selbst. `.ev-inhalt` erzwingt seither `flex-direction: row
  !important` (wie die `--fc-event-*`-Variablen oben, Abschnitt 8 Beginn).
  Vollständiger Text bleibt über `title`/`aria-label` am `.ev-titel`-Element
  und den Detail-Dialog erreichbar.
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

- Lokal: docker-compose mit PHP 8.5 + MySQL, produktionsnah
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
  Verlegung/Absage manueller Spiele, kein Push bei Löschung); **Vermietungen**
  (Issue #36: Nicht-Blockade-Regression – Belegung/Spiel über eine
  überlappende Vermietung hinweg wird gespeichert, ohne Bestätigungszwang
  durch die Vermietung, Hinweis nur in `ConflictCheckResult::$hinweise`;
  Raum-Zuordnung, leere raum_ids-Liste = ganzes Sportheim; Platz ohne
  sportheim_id erzeugt nie einen Hinweis; `pitch.sportheim_id`-NULL-
  Upcasting beim Replay analog `pitch.farbe`; Sportheim-/Raum-Delete-Guards
  – referenzierende Räume/Plätze/Vermietungen bzw. Vermietungen →
  deaktivieren statt löschen; Offline-Parität für den neuen Termintyp,
  s. u.); **Bereich-
  Upcasting** (Issue #27: Alt-Team-Event mit nur dem Enum-String → über die
  System-Seed-Events im Event-Log auf die passende bereich_id gehoben,
  deterministisch unabhängig von der Replay-Reihenfolge relativ zu den
  Seed-Events, unverändert auch nach Umbenennung des Bereichs); Bereiche-
  CRUD (Delete-Guard bei referenzierenden Teams, Deaktivieren statt Löschen,
  ein bereits zugewiesener – nun inaktiver – Bereich bleibt für das eigene
  Team gültig, neue Zuweisungen an ihn werden abgelehnt); Drag&Drop-
  Sortierung (nur tatsächlich verschobene Zeilen erhalten ein Updated-Event,
  eine Transaktion); **Terminlisten-Abbruch über Lücken** (Issue #52:
  `naechster` als untere Schranke – Sprung über die reale Datenlage „letzter
  Termin 15.11., nächster 07.03. des Folgejahres" erreicht den Folgetermin,
  Lücke > 1 Jahr beendet die Kette nicht, „wirklich kein Termin mehr" endet
  terminierend mit Abschlusshinweis, Slot-Regel als frühester Kandidat,
  laufende Sperrung zählt nicht als „nächster"; Filter senken die Schranke
  nicht); **Platzfarb-Darstellung** (Issue #57,
  `tests/js/kalender-pitch.test.js`: vollständige Matrix Darstellung × Breite
  × Platzfilter über `platzFarbDarstellung()`, komponiert mit
  `hatResourceSpalten()` – Hintergrund nur in Tag/Woche ohne Spalten, Punkt
  nur im Monat, „keine" in Ressourcen-Views/Liste/bei Einzelplatz, und
  derselbe Termin liefert je Darstellung ein anderes Ergebnis – die
  Eigenschaft, die die eingebackene Variante verletzte); **Offline-
  Paritätstests** (Issue #25): goldene Fixtures
  (`tests/fixtures/parity/bundle.json` + `cases.json`, inkl. beider
  DST-Wochenenden, überlappender Slots, mehrtägiger vor dem Zeitraum
  beginnender Sperrung, sowie – Issue #36 – Sportheime/Räume/Vermietungen
  inkl. einer raumbezogenen und einer Ganzhaus-Vermietung, eines Platzes mit
  sportheim_id und eines eigenen `vermietung`-Falls) prüfen, dass die
  clientseitigen Ports
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
