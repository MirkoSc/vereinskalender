# CLAUDE.md – Vereinskalender

Webkalender für einen Fußballverein: Sportplatz-Belegung (Training) und Spielplan.
Läuft auf Shared Hosting (PHP + MySQL). Dieses Dokument ist die
verbindliche Architektur. Bei Widersprüchen zwischen Code und diesem Dokument
gilt dieses Dokument – oder es wird hier bewusst geändert und begründet.

## 1. Zielbild

Zwei öffentliche Kalenderansichten:

1. **Platzbelegung**: Wochenraster, welches Team wann welchen Platz belegt
   (wiederkehrende Trainingsslots mit Gültigkeitszeitraum, Ausnahmen, Platzsperrungen).
2. **Spielplan**: Einzeltermine, welches Team wann wo spielt. Wird automatisch
   aus ICS-Feeds (z. B. fussball.de) importiert.

## 2. Harte Umgebungs-Constraints (niemals verletzen)

- Shared Hosting bei shared hosting: **kein SSH, kein Git, kein Composer, kein `exec()`/`shell_exec()`** auf dem Server.
- Deployment ausschließlich über Release-ZIPs (GitHub Releases) + Web-Installer/Self-Updater. FTP nur einmalig für `setup.php`.
- **Zielversion PHP 8.5** – aller Code und alle Abhängigkeiten müssen unter
  PHP 8.5 fehler- und deprecation-frei laufen (im Kontrollpanel wird PHP 8.5 eingestellt).
  MySQL/MariaDB, `ZipArchive` verfügbar. PDO mit Prepared Statements, `ERRMODE_EXCEPTION`.
  Moderne Sprachfeatures (Enums, readonly, Konstruktor-Promotion, `match`) aktiv nutzen.
- PHP-Laufzeitlimits beachten: **kein einzelner Request darf lange laufen**. Lange Vorgänge (Update, Import großer Dumps) sind Schrittketten aus kurzen Requests mit Statusdatei.
- Kein Build-Step auf dem Server. `vendor/` wird im Release-ZIP mitgeliefert (GitHub Action baut mit `composer install --no-dev`).
- Kein SPA-Framework. Server-gerendertes PHP + Vanilla JS + modernes CSS.

## 3. Verzeichnislayout (Server)

```
/web/                DocumentRoot (im Kontrollpanel eingestellt, wird NIE geändert)
   index.php           enthält nur: require dirname(__DIR__).'/current/public/index.php';
/current/            aktives Release (per rename() umgeschaltet)
/releases/
   vX.Y.Z/            app/, public/, vendor/, bin/, migrations/, VERSION
/shared/             überlebt jedes Update:
   config.php          DB-Zugang, Bootstrap-Admin-Credentials, App-Secrets
   var/backups/        Backup-ZIPs (per .htaccess gesperrt, Rotation: letzte 10)
   var/log/
   maintenance.flag    existiert nur während des Umschaltens
   update_state.json   Zustand einer laufenden Update-Schrittkette
```

Repo-Struktur spiegelt ein Release: `app/` (src/ mit Domain, Repository, Service;
views/), `public/` (index.php Front-Controller, css/, js/, manifest.json, sw.js),
`bin/` (import_ics.php, cron-fähige Skripte), `migrations/` (nummerierte .sql),
`setup.php` (Bootstrap-Installer, liegt im Repo-Root und wird als eigenes
Release-Asset veröffentlicht), `tests/`.

## 4. Datenmodell

Fachliche Tabellen sind **Projektionen** des Event-Logs (Abschnitt 5): PK `id`
kommt aus dem Event (**kein AUTO_INCREMENT**), Löschungen sind delete-Events
(die Zeile verschwindet aus der Projektion; die Historie lebt im Event-Log,
ein separates Soft-Delete-Feld ist unnötig). Technische Tabellen (admin,
schema_version, rate_limit, import_source-Laufstatus) sind KEINE Projektionen.

- **team**: bereich ('G'|'F'|'E'|'D'|'C'|'Herren'), name (z. B. "E2"),
  kuerzel, farbe (Hex aus vordefinierter Palette), aktiv (bool), sortierung.
  Ein Bereich (Jugend/Herren) kann mehrere Mannschaften haben; jede
  Mannschaft hat ihre eigene import_source. Inaktive Teams verschwinden aus
  Filtern und Neuanlagen; ihre Historie und Events bleiben erhalten.
- **pitch** (Sportplatz): venue_id FK (Heimverein), name, typ, flutlicht,
  adresse NULL (nur falls abweichend vom Verein), sortierung
- **training_slot**: team_id FK, pitch_id FK, wochentag (1–7), beginn, ende,
  gueltig_ab, gueltig_bis. Wiederholungsregel, wird zur Laufzeit
  für den angefragten Zeitraum zu konkreten Terminen expandiert.
- **slot_exception**: slot_id FK, datum, grund. Einzelner Ausfall eines Slots.
- **pitch_restriction**: pitch_id FK, von, bis, art ('gesperrt'|'eingeschraenkt'),
  grund (Pflichtfeld). Semantik: 'gesperrt' → Konfliktprüfung lehnt neue
  Belegungen ab; 'eingeschraenkt' → Belegen erlaubt, aber Buchungsdialog
  zeigt Warnung mit Grund, betroffene Termine tragen im Kalender eine
  sichtbare Markierung.
- **match**: team_id FK, anstoss (datetime), gegner, heimspiel (bool),
  ort_text (LOCATION aus ICS, roh), pitch_id FK nullable (nur Heimspiele),
  status ('geplant'|'abgesagt'), import_source_id FK nullable, ics_uid,
  ics_sequence, sync_hash. **UNIQUE(import_source_id, ics_uid)**.
- **import_source**: team_id FK, ics_url, aktiv, letzter_lauf, letzter_status, fehlertext
- **venue** (Heimverein/Spielstätte): name, farbe, adresse,
  default_pitch_id FK NULL, sortierung. Es gibt mehrere Heimvereine
  (Spielgemeinschaft); jeder hat 1..n Plätze (pitch.venue_id).
- **venue_begriff**: venue_id FK, begriff (Match-Keyword), sortierung.
  Mehrere Begriffe je Verein möglich (z. B. für einen Platz mit abweichendem
  Ortsnamen).
- **admin**: username UNIQUE, password_hash (password_hash()/password_verify())
- **event**: siehe Abschnitt 5 – die Quelle der Wahrheit. Alle fachlichen
  Tabellen oben sind Projektionen daraus.
- **schema_version**: version INT, angewendet_am
- **rate_limit**: ip, fenster_beginn, anzahl (für Schreib-Rate-Limit)

Konfigurationswerte (Auswärts-Farbe, Vereinsname, …) in einer **setting**-Tabelle
(key/value), nicht in Dateien – damit sie im DB-Backup landen.

## 5. Event Sourcing & Versionshistorie

Quelle der Wahrheit ist die append-only Tabelle **event**. Alle fachlichen
Tabellen sind daraus abgeleitete Projektionen und jederzeit per Replay
rekonstruierbar. Es gibt KEINEN Schreibweg an den Events vorbei – auch der
ICS-Import und Admin-Änderungen erzeugen Events.

- **event**: id (BIGINT AUTO_INCREMENT = globale Reihenfolge), aggregat_typ,
  aggregat_id, event_typ ('created'|'updated'|'deleted'), payload JSON,
  editor_name, ip, quelle ('web'|'admin'|'import'|'system'), erstellt_am,
  excluded_at NULL, excluded_von NULL, excluded_grund NULL,
  korrektur_von_event_id NULL. Indizes auf ip, editor_name,
  aggregat_typ+aggregat_id, erstellt_am.
- **Schreibpfad**: jeder Schreibvorgang erzeugt genau ein Event und wendet es
  in derselben DB-Transaktion auf die Projektion an.
- **Determinismus-Regeln** (Voraussetzung für korrekten Replay):
  - `aggregat_id` wird beim Schreiben des Events aus einer eigenen
    Sequenz-Tabelle vergeben und steht IM Event. Projektionen übernehmen sie
    als PK – kein AUTO_INCREMENT in Projektionen, sonst verrutschen nach
    Ausschlüssen alle Referenzen.
  - `payload` ist ein **Vollbild** des Zielzustands aller fachlichen Felder,
    kein Diff. Damit kann der Replay Events auf nicht (mehr) existierende
    Aggregate gefahrlos überspringen.
  - Replay = Events in id-Reihenfolge anwenden; ausgeschlossene Events
    überspringen; Events, deren Aggregat oder FK-Ziel fehlt, überspringen und
    im **Replay-Report** auflisten. Replay ist deterministisch: gleicher
    Event-Bestand ⇒ gleiche Projektionen.
- **Events sind unveränderlich.** Entfernen aus dem Verlauf = `excluded_at`
  setzen (mit excluded_von + Grund), niemals DELETE. Bearbeiten = Original
  ausschließen + korrigierte Kopie einfügen (quelle='admin',
  korrektur_von_event_id → Original). Ausschlüsse sind dadurch selbst
  nachvollziehbar und rückgängig machbar.
- **Rebuild** (Admin): Projektionen in Schatten-Tabellen (`<name>_rebuild`)
  neu aufbauen – als Schrittkette in Batches (PHP-Timeout!), Fortschritt in
  Statusdatei – und am Ende per atomarem `RENAME TABLE`-Tausch aktivieren.
  Danach Replay-Report anzeigen.
- **Admin-UI Event-Historie**: Liste mit Filtern (IP, editor_name,
  aggregat_typ, event_typ, quelle, Zeitraum), Detailansicht mit Payload,
  Einzel-Ausschluss, Massenaktion „alle Events dieser IP/dieses Namens
  ausschließen", Korrektur-Editor, Ausschluss aufheben, Rebuild-Button mit
  Fortschrittsanzeige.
- **DSGVO**: IP-Adressen sind personenbezogen. Zweck (Missbrauchsabwehr) in
  der Datenschutzerklärung dokumentieren; IPs in Events nach 90 Tagen
  anonymisieren (Setting, per Cron-Lauf) – das begrenzt bewusst das Fenster
  für IP-basierte Bereinigung.

## 6. Zugriffsmodell

Drei Ebenen, bewusst ohne klassisches Benutzerkonto:

1. **Lesen**: komplett öffentlich, keine Session.
2. **Ändern (öffentlich)**: Client fragt beim ersten Schreibversuch nach einem
   Namen, speichert ihn in `localStorage`. Jeder Schreib-Request trägt
   `editor_name` mit; Server lehnt Schreiben ohne Namen ab, prüft ihn aber
   nicht weiter (bewusstes Vertrauensmodell). Absicherung: Event-Historie
   (Abschnitt 5, alles nachvollzieh- und rückrollbar) + Rate-Limit pro IP (~30 Schreibzugriffe/Minute).
3. **Admin**: Login mit username + password_hash, PHP-Session.
   **Bootstrap-Regel**: Die in `shared/config.php` hinterlegten
   Bootstrap-Credentials sind NUR gültig, solange die `admin`-Tabelle leer ist.
   Erster Login damit erzwingt das Anlegen eines echten Admins; sobald eine
   Zeile existiert, werden Bootstrap-Credentials abgewiesen. Kein Flag, kein Zustand.

Admin-Funktionen: Teams (inkl. Farbe per Paletten-Picker), Plätze, Spielstätten
(Begriff + Farbe, Reihenfolge per Drag & Drop), Import-Quellen, Event-Historie
(Abschnitt 5: filtern, ausschließen, korrigieren, Rebuild), Backup
erstellen/herunterladen, Update einspielen, **Saison-Assistent**: geführter
Ablauf zum Saisonwechsel – Teams umbenennen/deaktivieren/anlegen,
Import-URLs erneuern (fussball.de vergibt pro Saison neue ICS-URLs),
Trainingsslots der Vorsaison als Kopiervorlage übernehmen.

CSRF-Schutz für alle Schreibrouten (Token). Passwörter nie loggen.

### Admin-Dashboard (Nutzungsstatistik)

- Aggregierte Zähler statt Zugriffsprotokoll: Tabelle **usage_stat**
  (datum DATE, metrik, dimension NULL, anzahl, UNIQUE(datum, metrik,
  dimension)), Inkrement per `INSERT … ON DUPLICATE KEY UPDATE`. Keine IPs,
  keine User-Agents, keine Cookies → DSGVO-unkritisch, kein Consent nötig.
  Technische Tabelle, keine Projektion.
- Serverseitige Erfassung im Front-Controller: Seitenaufrufe je Route,
  API-Abrufe, ICS-Feed-Abrufe (dimension = team/pitch), Offline-Bundle.
- Clientseitige Erfassung per `navigator.sendBeacon` an `POST /api/stat`:
  Moduswechsel, Filternutzung, Push-Abo, PWA-Installation. Endpoint
  akzeptiert NUR eine Whitelist fester Metriknamen, Rate-Limit gilt.
- Dashboard im Admin: Kennzahlen (heute / 7 / 30 Tage), Tagesverlauf als
  Balken, Top-Routen, Feature-Zähler, ICS-Abo-Abrufe je Feed, Anzahl
  aktiver Push-Subscriptions. Rendering ohne Chart-Bibliothek (CSS/SVG-Balken
  genügen).
- **Betriebs-Monitoring** (prominent im Dashboard): „Letzter Import: vor
  X Min" – grün < 30 Min, rot > 60 Min (Cron tot). Warnung je Feed, wenn er
  fehlerhaft ist oder länger keine Zukunftstermine mehr liefert
  (Saisonende-Indikator). **Alarm-Mail** an den Admin (PHP `mail()`) bei
  Importfehlern und fehlgeschlagenen Update-Schritten; Mail-Adresse als
  Setting, Drosselung max. 1 Mail/Thema/Tag.

## 7. ICS-Import & Spielverlegungen

- Cronjob im Kontrollpanel ruft `bin/import_ics.php` alle 10 Minuten per HTTP auf
  (mit Secret-Token in der URL, Token liegt in config.php).
- Pro aktiver `import_source`: Feed laden, Events parsen. Sync-Logik pro Event:
  - Schlüssel `(import_source_id, ics_uid)` in DB suchen.
  - **Unbekannt** → INSERT als neues Spiel.
  - **Bekannt + sync_hash geändert** → UPDATE (so wandert ein verlegtes Spiel
    automatisch an die richtige Stelle; die UID bleibt bei Verlegung gleich,
    DTSTART/SEQUENCE ändern sich).
  - **Unverändert** → überspringen.
  - Nachlauf: UIDs, die in der DB existieren, aber im Feed fehlen →
    `status = 'abgesagt'`. NIEMALS hart löschen (schützt auch gegen leere Feeds).
- `sync_hash` = Hash über anstoss + ort_text + gegner + summary-relevante Felder.
- Heimspiel-Erkennung über den `VenueMatcher`: matcht der `ort_text` auf
  einen Heimverein → heimspiel=true. Der konkrete Platz steht NICHT im ICS:
  der Import belegt `pitch_id` mit dem `default_pitch_id` des Vereins vor;
  die Zuordnung ist danach manuell änderbar (namensbasiert, als Event).
  Heimspiele ohne verlässliche Platz-Zuordnung zeigt die
  Verfügbarkeitsansicht als Hinweis-Layer „Heimspiel, Platz offen" beim
  betroffenen Verein – niemals stillschweigend als „frei".
- Fehler pro Quelle isolieren: eine kaputte Quelle darf die anderen nicht stoppen;
  Fehlertext in `import_source` speichern und im Admin anzeigen.

## 8. Anzeigemodi, Farben, Filter

- API `GET /api/events?von=&bis=&typ=&team=&venue=` liefert pro Event IMMER
  beide Farbfelder: `team_farbe` und `venue_farbe` + `venue_id`.
- Spielstätten-Auflösung zur **Anzeigezeit** (nicht beim Import), damit neue
  Begriffe rückwirkend wirken: erster `venue_begriff` (nach `sortierung`),
  der case-insensitive im `ort_text` vorkommt → dessen venue + Farbe.
  Kein Treffer → Auswärtsspiel, globale „Auswärts"-Farbe (setting).
  Die Auflösung lebt in EINEM `VenueMatcher`-Service, den Anzeige UND
  Import nutzen.
- Moduswechsel (Team ↔ Spielstätte) ist reines Frontend: Umschalter entscheidet
  nur, welches Farbfeld gerendert wird, kein neuer Request.
- Filter: `team=<id>`, `bereich=<G|F|E|D|C|Herren>` (alle Mannschaften des
  Bereichs), `venue=<id>`, `venue=heim` (alle eigenen Vereine) oder
  `venue=auswaerts` (alle ungematchten Orte).
- **Vereinssicht „Bei uns"**: Kombi-Ansicht je Heimverein (oder beide) mit
  Heimspielen + Belegungen + Restriktionen – umgesetzt als voreingestellte
  Filterkombination, keine eigene Datenlogik.
- Teamfarben kommen als CSS-Variablen aus der DB: Front-Controller rendert
  `:root { --team-<id>: #…; }`, Events referenzieren die Variable.

## 9. Frontend / UI

- Server-gerendertes PHP + Vanilla JS + ein handgeschriebenes Stylesheet
  (CSS Custom Properties, Grid, clamp(), `prefers-color-scheme` Dark Mode).
  Mobile-first; Breakpoints ~768px (Tablet) und ~1100px (Desktop-Sidebar).
- **FullCalendar** für beide Kalender. Ansicht nach Breite: Desktop
  `timeGridWeek`/`dayGridMonth`, Mobil `listWeek`. Platzbelegung mit Plätzen
  als Spalten über FullCalendar Premium Resource-Views – Lizenzschlüssel
  `GPL-My-Project-Is-Open-Source` (Projekt ist GPL-lizenziert auf GitHub).
- Mobile-Patterns: Termindetails als Bottom-Sheet, Filter als horizontal
  scrollbare Chips, Anzeigemodus als Segmented Control, Touch-Ziele ≥ 44 px.
- **PWA mit Offline-Fenster**: manifest.json (Vereinslogo, Theme-Farbe) +
  Service Worker, der die App-Shell cached. Datenhaltung offline über
  `GET /api/offline-bundle`: EIN JSON mit allen Events von heute bis heute+7
  Tagen plus Teams, Spielstätten, Farben und relevanten Settings (beide
  Anzeigemodi + Filter müssen offline voll funktionieren). Die App lädt das
  Bundle bei jedem Online-Besuch frisch und legt es mit Zeitstempel in
  IndexedDB ab. Offline: Rendern aus dem Bundle; Navigation außerhalb des
  7-Tage-Fensters zeigt einen Hinweis; Banner „Offline – Stand: <Zeit>" ist
  Pflicht. **Schreiben ist offline gesperrt** (freundliche Meldung, keine
  Offline-Schreibqueue).
- **Web-Push-Benachrichtigungen**: Kategorien Platzsperrung und
  Spielverlegung/-absage, optional je Team filterbar. Opt-in NUR per
  explizitem Klick (Glocken-Button, nie beim Seitenaufruf). Tabellen (beides
  technische Tabellen, keine Projektionen): **push_subscription** (endpoint,
  p256dh, auth, praeferenzen JSON, erstellt_am) und **notification_queue**
  (typ, payload JSON, ausgeloest_von_event_id FK, erstellt_am, gesendet_am).
  Befüllung der Queue ist ein Konsument des Event-Logs: `pitch_restriction
  created` (beide Arten: gesperrt UND eingeschraenkt) und `match updated` mit
  geändertem Anstoß bzw. `status='abgesagt'` erzeugen Queue-Einträge im
  Schreibpfad. Versand im Cron-Lauf (direkt nach
  dem ICS-Import) über `minishlink/web-push` mit VAPID-Schlüsselpaar (bei
  Installation erzeugt, liegt in `shared/`); Antworten 404/410 → Subscription
  löschen. Payload: Titel, Text, Deep-Link auf den Termin. iOS-Hinweis in der
  UI: Push erst nach „Zum Homescreen hinzufügen" (iOS ≥ 16.4).
- Cache-Busting: Asset-URLs mit `?v=<VERSION>`; Service-Worker-Cache-Name
  enthält die Version. Beides wird beim Release aus der VERSION-Datei gespeist.
- **Verfügbarkeitsansicht** (öffentlich): Zeitstrahl-Raster je Platz aus
  `GET /api/verfuegbarkeit?von=&bis=` – pro Platz Intervalle mit Zustand
  frei | belegt | eingeschraenkt | gesperrt, bei Restriktionen inkl. Grund.
  Plätze gruppiert nach Heimverein, mit Adresse (inkl. abweichender
  Platz-Adressen); Hinweis-Layer für Heimspiele ohne sichere Platz-Zuordnung.
  Freie Lücken werden nur innerhalb des Settings **Nutzungszeiten**
  (z. B. 08:00–22:00) berechnet. 'eingeschraenkt' liegt als Grund-Layer
  hinter Belegungen (beides gleichzeitig sichtbar); Tap öffnet Details
  (Zeitraum, Grund) im Bottom-Sheet. Restriktions-Daten gehören auch ins
  offline-bundle.
- **Rechtsseiten**: Impressum und Datenschutzerklärung als
  admin-editierbare Inhaltsseiten (setting/page-Inhalt, Markdown), nicht
  hartkodiert – Pflege ohne Release. Datenschutzerklärung muss abdecken:
  IP-Verarbeitung im Event-Log (Zweck Missbrauchsabwehr, 90-Tage-
  Anonymisierung), Web-Push-Abos, Hosting bei shared hosting. Footer-Links auf
  jeder Seite.
- **Farbe ist nie das einzige Signal**: Events tragen in beiden Anzeigemodi
  immer auch Text (Team-Kürzel bzw. Ortsname), Zustände in der
  Verfügbarkeitsansicht immer ein Textlabel. Gilt als Abnahmekriterium für
  jede Ansicht (Farbenblindheit).
- **Kalender-Abos (Google/iOS/Outlook)**: ICS-Feeds unter stabilen URLs –
  `/export/team/<id>.ics` (Spiele je Team), `/export/spiele.ics` (alle),
  `/export/platz/<id>.ics` (Belegung je Platz). Events mit **stabilen UIDs**
  aus `aggregat_id`, damit Verlegungen im Abo den Termin verschieben statt
  Duplikate zu erzeugen. Header `Content-Type: text/calendar; charset=utf-8`,
  `REFRESH-INTERVAL`/`X-PUBLISHED-TTL` setzen. Eigene „Abonnieren"-Seite:
  Feed-Auswahl, `webcal://`-Link (iOS/Apple), Google-Link
  (`calendar.google.com/calendar/r?cid=<feed-url>`), „URL kopieren" +
  Kurzanleitung für Outlook. Hinweis auf der Seite: Google/Outlook
  aktualisieren Abos nur alle paar Stunden – für kurzfristige Änderungen ist
  Web Push der schnelle Kanal. Feed-Abrufe fließen in usage_stat.

## 10. Self-Update & Backup

Update-Schrittkette (Admin-UI ruft Schritte einzeln per AJAX, Zustand in
`shared/update_state.json`; jeder Schritt idempotent und < 30 s):

1. **Versionscheck**: GitHub Releases API, Vergleich mit lokaler
   VERSION-Datei. Download-URL ist fest auf das eigene Repo verdrahtet,
   niemals aus Nutzereingaben. **Update-Kanal** als Setting:
   'stable' (Standard, ignoriert Pre-Releases; nutzt `/releases/latest`)
   oder 'beta' (berücksichtigt auch Pre-Releases; nutzt `/releases` und
   wählt das neueste). Die Subdomain-Testinstanz läuft auf 'beta' – jedes
   Release wird dort als Pre-Release erprobt, bevor es als regulärer
   Release für die Produktivinstanz freigegeben wird.
2. **Backup**: DB-Dump in reinem PHP (ifsnop/mysqldump-php), ZIP mit
   `dump.sql` + `config.php` + `manifest.json` (App-Version, Zeitstempel)
   nach `shared/var/backups/`. Auch manuell per Admin-Button auslösbar.
3. **Download + Entpacken**: Release-ZIP laden, SHA-256 gegen `checksums.txt`
   aus dem Release prüfen, nach `releases/vX.Y.Z/` entpacken. Läuft die alte
   Version dabei weiter? Ja – sie wird nie berührt.
4. **Umschalten**: `maintenance.flag` setzen → `rename(current, releases/_prev)`
   → `rename(releases/vX.Y.Z, current)` → Flag entfernen. rename() auf demselben
   Dateisystem ist atomar. Stirbt PHP dazwischen, ist der Zustand aus dem
   Dateisystem eindeutig rekonstruierbar; die Schrittkette repariert beim
   nächsten Aufruf.
5. **Migrationen**: alle `migrations/NNN_*.sql` > `schema_version` einspielen,
   Version fortschreiben. Migrationen müssen **eine Version rückwärtskompatibel**
   sein (Spalte hinzufügen ja; umbenennen/löschen erst in der Folgeversion).
   Bei Fehler: Wartungsmodus bleibt, Admin wählt Rollback oder Wiederholen.
6. **Abschluss**: Selbsttest (Startseite + /api/events antworten 200),
   vorletztes Release löschen (die letzten 2 behalten).

**Rollback** = `_prev` zurück-renamen; dank rückwärtskompatibler Migrationen
läuft der alte Code auf dem neuen Schema. Daten-Rollback nur über Backup-Restore.

**setup.php** (Bootstrap, Nextcloud-Stil, eigenes Release-Asset): Umgebungscheck
(PHP-Version, ZipArchive, Schreibrechte, rename) als Checkliste → neuestes
Release laden/prüfen/entpacken, `web/`-Shim + `shared/` anlegen → Weiterleitung
auf `/install` → dort DB-Zugangsdaten + Verbindungstest, dann „frische
Installation" (alle Migrationen ab 0) ODER „Backup einspielen" (ZIP hochladen,
dump.sql häppchenweise importieren – Offset in Statusdatei wegen Timeout –,
danach nur Migrationen > Backup-Stand nachziehen) → config.php schreiben
(sperrt den Installer) → setup.php löscht sich selbst.
`/install` ist NUR erreichbar, solange `shared/config.php` fehlt.
Der Download/Entpack/Prüf-Code von setup.php und Self-Updater ist derselbe.

## 11. Release-Prozess (GitHub)

- Ablauf je Version: Tag als **Pre-Release** veröffentlichen → auf der
  Testinstanz (Kanal 'beta') einspielen und prüfen → bei Erfolg das Release
  auf „latest" umstellen, dann zieht es die Produktivinstanz (Kanal 'stable').
- GitHub Action bei Tag `vX.Y.Z`: `composer install --no-dev --optimize-autoloader`,
  VERSION-Datei schreiben, ZIP packen (app/, public/, vendor/, bin/, migrations/,
  VERSION), `checksums.txt` (SHA-256) erzeugen, beides + `setup.php` als
  Release-Assets veröffentlichen.
- Lizenz: GPLv3 (Voraussetzung für den FullCalendar-Open-Source-Key).

## 12. Entwicklungs-Konventionen

- Lokale Umgebung: `docker-compose.yml` mit **PHP 8.5** + MySQL, produktionsnah
  konfiguriert (`disable_functions = exec,shell_exec,…`, realistische
  memory/time limits). App muss darin per `docker compose up` laufen.
- Tests: PHPUnit. Pflicht-Testabdeckung für: Event-Schreibpfad (Event +
  Projektion in einer Transaktion), Replay-Determinismus (Events anwenden ⇒
  Projektionen identisch zum Live-Stand), Ausschluss-Szenario (Events einer
  IP ausschließen → Rebuild → Änderungen weg, Fremd-Events intakt, verwaiste
  Events im Report), Korrektur-Events, ICS-Sync (insert/update/skip/
  abgesagt, Verlegung per gleicher UID), Spielstätten-Matching (Reihenfolge,
  case-insensitive, Auswärts-Fallback), Slot-Expansion inkl. Ausnahmen und
  Restriktionen, VenueMatcher (Mehrfach-Begriffe, Priorität, Heim vs.
  auswärts), Konfliktprüfung (gesperrt blockiert, eingeschraenkt
  erlaubt mit Warnung), Verfügbarkeitsberechnung (freie Lücken innerhalb der
  Nutzungszeiten), Migrationslauf von 0, Backup-Erstellen +
  Restore-Roundtrip, Bootstrap-Admin-Regel.
- Konfliktprüfung gehört in einen `BookingService`: beim Speichern eines Slots
  werden bestehende Slots + Spiele + Restriktionen desselben Platzes im
  Gültigkeitszeitraum expandiert und auf Überschneidung geprüft (nicht als
  DB-Constraint lösbar). Dieselbe Expansionslogik speist die
  Verfügbarkeitsansicht (Umkehrung: freie Lücken).
- **Zeitzonen-Konvention**: Alles in `Europe/Berlin` denken und speichern
  (DATETIME als lokale Zeit, Konvention im Code dokumentiert;
  `date_default_timezone_set('Europe/Berlin')` zentral im Bootstrap).
  ICS-`TZID` beim Import nach Europe/Berlin konvertieren. Slot-Expansion
  und ICS-Export müssen über Sommerzeit-Umstellungen korrekt sein –
  Pflicht-Tests über beide Umstellungswochenenden.
- SQL nur über Repositories mit Prepared Statements. Kein Query-Builder-Framework.
- Alle Ausgaben escapen (htmlspecialchars); JSON-Antworten mit korrekten Headern.
- Sprache: UI-Texte Deutsch, Code/Kommentare/Commits Englisch.
- CI (GitHub Action) führt Tests unter PHP 8.5 aus mit
  `error_reporting(E_ALL)`; Deprecation-Warnungen gelten als Fehler.
  Fremdbibliotheken vor Aufnahme auf PHP-8.5-Kompatibilität prüfen (insbesondere
  ifsnop/mysqldump-php – falls inkompatibel, Dump-Logik selbst implementieren,
  das ist überschaubar: SHOW TABLES, SHOW CREATE TABLE, batched INSERTs).
- Jede Schema-Änderung = neue nummerierte Migration; niemals alte Migrationen ändern.
- Vor jedem Merge: Tests grün + `docker compose up` + manueller Smoke-Test
  von setup.php-Flow in frischem Container.

## 13. Meilensteine

1. **Gerüst**: Repo, docker-compose, Front-Controller + Router, config-Handling,
   Migrationssystem, GitHub Action für Release-Build.
2. **Event-Store + Datenmodell + Admin-Basis**: Migrationen 001–00x,
   Event-Tabelle + Sequenz + Schreibpfad, Projektionen, Replay/Rebuild-Service
   (inkl. Schatten-Tabellen-Tausch), Admin-Login inkl. Bootstrap-Regel,
   CRUD Teams/Plätze/Spielstätten (eventbasiert).
3. **Öffentliche Kalender**: /api/events (Slot-Expansion, Farbauflösung, Filter),
   Platzbelegungs- und Spielplan-Ansicht mit FullCalendar, Anzeigemodi,
   Verfügbarkeitsansicht (/api/verfuegbarkeit, Nutzungszeiten-Setting,
   Restriktions-Semantik gesperrt/eingeschraenkt),
   Namens-Abfrage + Schreibpfad für Belegung.
4. **ICS-Import**: import_source-Verwaltung, bin/import_ics.php, Sync-Logik,
   Cron-Endpoint mit Token.
5. **Installer + Self-Update**: setup.php, /install (frisch + Restore),
   Backup-Service, Update-Schrittkette, Rollback.
6. **Feinschliff**: PWA inkl. Offline-Fenster (offline-bundle-Endpoint,
   IndexedDB, Offline-Banner + Fenster-Hinweis, Schreibsperre offline),
   Web-Push (Abo-UI mit Kategorien/Team-Filter, VAPID, notification_queue,
   Versand im Cron), Kalender-Abo-Seite + ICS-Feeds, Nutzungs-Dashboard
   (usage_stat, /api/stat-Beacon, Betriebs-Monitoring + Alarm-Mail),
   Saison-Assistent, Rechtsseiten (Impressum/Datenschutz, editierbar),
   Dark Mode, Event-Historien-UI (Filter,
   Ausschluss, Korrektur, Rebuild mit Fortschritt), IP-Anonymisierungs-Cron,
   Rate-Limit, Doku (README mit Installationsanleitung).
