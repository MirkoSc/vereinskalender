# CLAUDE.md – Vereinskalender

Webkalender für einen Fußballverein (Spielgemeinschaft): Sportplatz-Belegung
(Training), Spielplan (ICS-Import), Verfügbarkeit. Die Anwendung ist
implementiert und in Betrieb; dieses Dokument ist die verbindliche
Architektur-Referenz. Bei Widersprüchen zwischen Code und Dokument gilt das
Dokument – oder es wird hier im selben PR bewusst geändert.
Änderungswünsche werden als GitHub Issues geführt, nicht in dieser Datei.

## 1. Harte Umgebungs-Constraints (niemals verletzen)

- Hosting bei einem Shared-Hosting-Anbieter: **kein SSH, kein Git, kein Composer, kein
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
/web/                DocumentRoot (fix): index.php = Shim (Wartungsmodus- und
                     Fehlt-das-Release-Prüfung, dann require
                     ../current/public/index.php – Inhalt in
                     `ReleaseSwitcher::SHIM`, Abschnitt 10)
                     + .htaccess (Rewrite auf index.php, Security-Header:
                     nosniff, Referrer-Policy, CSP `frame-ancestors`/
                     `base-uri`, HSTS). Beide Dateien schreibt setup.php
                     bei der Neuinstallation; kein Release-ZIP fasst den
                     DocumentRoot je an. Für index.php holt das der
                     **Updater selbst nach** (`UpdateService::finish()`,
                     Abschnitt 10) – die .htaccess dagegen NICHT (sie darf
                     handgepflegt sein und wird nie überschrieben), ihre
                     Header müssen bei Bestandsinstallationen also von Hand
                     nachgezogen werden. Inhalt spiegelbildlich in
                     docker/web/.
                     Die CSP trägt bewusst NUR script-unabhängige
                     Direktiven: `abonnieren.php`/`install.php` haben noch
                     Inline-`<script>`-Blöcke und die Admin-Listen
                     Inline-`onsubmit`-Handler – ein `script-src` würde die
                     Lösch-Bestätigungen lautlos abschalten. Erst wenn die
                     ausgelagert sind, kann sie auf `default-src`/
                     `script-src`/`style-src` erweitert werden.
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
  Slot), pitch_id FK, wochentage (Liste 1..n aus 1–7), intervall_wochen,
  beginn, ende, gueltig_ab, gueltig_bis. Wiederholungsregel, zur Laufzeit
  expandiert. Übergangsweise (eine Version, für Rollback) behält die
  Projektion die
  Alt-Spalten team_id/wochentag = erstes Listenelement; Alt-Events werden
  beim Replay per Payload-Normalisierung aufs Listenformat gehoben.
  **Rhythmus** (intervall_wochen, 1 = jede Woche, UI bietet 1–4;
  Migration 018, DEFAULT 1, Alt-Events ohne Feld werden beim Replay
  deterministisch auf 1 gehoben – Upcasting analog `pitch.farbe`): der Takt
  ist auf der **Woche des ERSTEN Termins der Serie** verankert, nicht auf
  jedem Wochentag einzeln und nicht auf dem abgefragten Zeitraum. Beides
  bewusst: Ein Anker je Wochentag würde die Wochentage eines Slots
  verschränken (bei gueltig_ab an einem Dienstag lägen Mo und Mi eines
  Mo+Mi-Slots in abwechselnden Wochen statt gemeinsam alle 14 Tage); ein
  Anker am Bereichsanfang (`max(rangeStart, gueltig_ab)`, das Verhalten vor
  dem Rhythmus) ließe denselben Slot in einer August-Abfrage andere Termine
  liefern als in einer September-Abfrage – Grid, Terminliste und
  Offline-Bundle widersprächen sich. Anker ist der erste Termin und nicht
  die gueltig_ab-Woche selbst, weil deren Wochentag sonst stillschweigend
  ausfiele („ab Sa 01.08., dienstags, alle 2 Wochen" begänne am 11.08. statt
  am 04.08.). Aus dem Wochen-Anker folgen die Bearbeitungs-/Lösch-Umfänge
  ohne Zusatzlogik: „dieser und alle folgenden" gibt der Fortsetzung
  gueltig_ab = Split-Datum – selbst eine Occurrence –, der Takt setzt dort
  also unverschoben neu an; das Kürzen behält gueltig_ab und damit den Anker
  des verbleibenden Teils. Ein Eintages-Slot (Einzeltermin bzw. „nur
  dieser") speichert immer intervall_wochen = 1; das im Einzeltermin-Modus
  ausgeblendete Select wird von FormData trotzdem gesendet, der Server
  überschreibt es deshalb, statt ihm zu vertrauen.
  `NextEventDate::ausSlots()/ausSlotsVor()` bleiben bewusst **wöchentlich**
  (Abschnitt 7): der wöchentliche Kandidat ist nie später als die echte
  N-wöchige Occurrence (rückwärts nie früher), beide Schranken gelten also
  weiter; ein exakter Nachbau wäre nur eine zweite Stelle, die mit
  `SlotExpander` synchron zu halten wäre.
  **Bearbeiten ist öffentlich** (Ebene 2) mit Umfangs-Rückfrage:
  „alle Termine" (Updated-Event), „dieser und alle folgenden" (Split:
  Updated kürzt gueltig_bis + Created für die Fortsetzung, atomar in einer
  Transaktion), „nur dieser" (slot_exception-Event + Created eines
  Eintages-Slots, atomar). **Einzeltermin** (Issue #83): ein Eintages-Slot
  ist damit nicht mehr nur Nebenprodukt des „nur dieser"-Splits, sondern im
  Anlege-Dialog direkt wählbar (Segmented Control „Serie"/„Einzeltermin",
  nur beim Neuanlegen sichtbar) – Teams, Platz, Datum, Beginn, Ende, ohne
  Wochentage/Gültigkeitszeitraum. Kein neues Aggregat, keine Migration,
  keine Sonderroute: der Client sendet `modus=einzeltermin` + `datum_neu`
  statt wochentage[]/gueltig_ab/gueltig_bis; `BookingService::
  applyEinzeltermin()` leitet vor der üblichen Validierung/Konfliktprüfung
  daraus den einen Wochentag sowie gueltig_ab == gueltig_bis == diesem
  Datum ab – derselbe Schreibpfad, dasselbe created-Event wie ein
  Serientermin. Bearbeiten eines bereits bestehenden Eintages-Slots (ein
  Wochentag + gueltig_ab == gueltig_bis, erkennbar unabhängig davon, ob er
  als Einzeltermin angelegt oder per „nur dieser" abgespalten wurde)
  überspringt die dreistufige Umfangs-Rückfrage automatisch und zeigt
  direkt die einfache Einzel-Bearbeitung (Datum statt Wochentage/
  Gültigkeitszeitraum); der Schreibvorgang bleibt Umfang „alle" (Update in
  place, keine Exception, kein Split). **Löschen ist ebenfalls öffentlich**
  (Ebene 2) mit derselben dreistufigen Umfangs-Rückfrage wie das Bearbeiten:
  „alle Termine" (ein Deleted-Event für die ganze Serie), „dieser und alle
  folgenden" (ein Updated-Event, das gueltig_bis auf den Vortag kürzt – bleibt
  dabei vor dem Schnitt KEINE Occurrence übrig, wird stattdessen die ganze
  Serie gelöscht statt ein terminloser, im Kalender unsichtbarer und damit
  unlöschbarer Rumpf-Zeitraum hinterlassen – dieselbe Degradierungs-Regel wie
  beim „nur dieser"-Split), „nur dieser" (ein slot_exception-Event, kein
  Löschen der Serie – exakt das Gegenstück zu „Ausfall eintragen", das
  daneben unverändert bestehen bleibt). Jeder Lösch-Umfang ist genau EIN
  Event, anders als beim Bearbeiten also nie eine Transaktion nötig.
  slot_exception-Zeilen, die nach einer „dieser und alle folgenden"-Kürzung
  hinter dem neuen gueltig_bis liegen, werden bewusst nicht bereinigt (bleiben
  inerte Historie, analog den Ausnahmen, die ein „nur dieser"-Split auf dem
  abgetrennten Teil zurücklässt); ein bereits bestehender Eintages-Slot
  überspringt die Rückfrage genau wie beim Bearbeiten. **Doppelbelegung** (mehrere
  Belegungen oder ein Spiel gleichzeitig auf demselben Platz) ist erlaubt:
  `BookingService::checkPayload()` legt eine Überlappung mit einer anderen
  Belegung oder einem Spiel als **Warnung** ab (`ConflictCheckResult::
  $warnings`, „Trotzdem speichern"-Bestätigung im Buchungsdialog wie bei
  manuellen Spielen), nicht mehr als harten `$conflicts`-Ablehnungsgrund –
  symmetrisch zu `checkMatch()` (Abschnitt darunter), das das schon vorher
  so hielt. Nur eine `gesperrt`-Restriktion blockiert weiterhin die
  gesamte Prüfung, unabhängig von einer gleichzeitigen Doppelbelegung. Der
  doppelt belegte Zustand ist dauerhaft sichtbar, nicht nur beim Speichern
  (Abschnitt 7/8): der Kalender markiert beide betroffenen Termine
  unübersehbar (⚠-Symbol + Rahmen/Schraffur, aus dem UNGEFILTERTEN
  geladenen Bestand abgeleitet, damit ein aktiver Filter die Warnung nicht
  verschwinden lässt), die Verfügbarkeitsansicht liefert je
  Zeitstrahl-Segment ein additives `labels`-Feld
  (`AvailabilityCalculator::buildTimeline()`/`offline-verfuegbarkeit.js`),
  sobald mehr als eine Belegung den Abschnitt deckt, statt die zweite
  stillschweigend zu verschlucken.
- **slot_exception**: slot_id FK, datum, grund.
- **pitch_restriction**: pitch_id FK, von, bis,
  art ('gesperrt'|'eingeschraenkt'), grund (Pflicht). 'gesperrt' →
  Konfliktprüfung lehnt neue Belegungen ab; 'eingeschraenkt' → Belegen
  erlaubt, Buchungsdialog warnt mit Grund, Termine tragen Markierung.
  **Bearbeiten/Löschen öffentlich** (Ebene 2) als Events, Löschen =
  delete-Event – exakt das Muster von manuellen Spielen/Vermietungen
  (Issue #64); Platz, Zeitraum und Grund sind alle änderbar (das Payload ist
  ohnehin immer ein Vollbild, kein Diff – kein Sonderfall für den Platz
  nötig). Ein Art-Wechsel wirkt sofort auf die Konfliktprüfung, die
  `pitch_restriction` bei jeder Prüfung live liest; er macht dadurch
  bestehende Belegungen **nicht** ungültig – `BookingService::
  occurrencesOnPitch()` liefert die davon betroffenen Trainings-/Spiel-
  termine im Zeitraum der Restriktion beim Schreiben (create UND update) als
  reine Hinweisliste zurück, rein informativ wie
  `ConflictCheckResult::$hinweise`. Push (Kategorie „Platzrestriktion")
  feuert bei Created UND Updated, nicht bei Deleted (analog dem
  Lösch-Verhalten bei manuellen Spielen).
- **match**: team_id FK, anstoss, ende NULL (nur bei manuellen Spielen
  gesetzt; der Import schreibt immer NULL; Anzeige, Konfliktprüfung,
  Verfügbarkeit und ICS-Export nutzen ende, sonst Fallback Anstoß + 2 Std.
  – zentral in `MatchDuration`; Alt-Events ohne Feld werden beim Replay
  deterministisch auf NULL gehoben), gegner, heimspiel, spielfrei (Issue
  #65: kein Spiel, sondern ein spielfreier Feed-Termin – leere LOCATION
  UND konfigurierbarer Begriff im SUMMARY, s. Abschnitt 6; Alt-Events ohne
  Feld werden beim Replay deterministisch auf false gehoben, Upcasting
  analog pitch_manuell. Issue #78: ein Spielfrei ist ein **Tages-Fakt**,
  kein Uhrzeit-Block – der Feed liefert ihn als DATE-TIME zu später
  Abendstunde (~23:59) am echten Tag, gespeichert bleibt dieser rohe
  `anstoss`; die **Ganztägigkeit** und der **maßgebliche Tag** =
  `date(anstoss)` (Tag des Anstoßes, NICHT des `+2h`-Endes – das läge sonst
  am Folgetag) werden erst zur Anzeige/im ICS-Export abgeleitet, NICHT in der
  DB normalisiert – konsistent mit „alles Darstellungsabhängige wird beim
  Rendern abgeleitet", Abschnitt 7),
  ort_text (ICS-LOCATION roh), pitch_id NULL (nur
  Heimspiele), pitch_manuell (true = manuelle Platz-Zuordnung, der Import
  fasst pitch_id dann nie an; Alt-Events ohne Feld werden beim Replay
  deterministisch auf false gehoben, Upcasting analog pitch.farbe),
  status ('geplant'|'abgesagt'), import_source_id NULL, ics_uid,
  ics_sequence, sync_hash. **UNIQUE(import_source_id, ics_uid)**.
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
- **vermietung** (Issue #36): sportheim_id FK, art (Issue #63:
  'vermietung'|'putzen'|'sitzung'), raum_ids (Liste 0..n – leer =
  gesamtes Sportheim), von, bis (DATETIME), titel (Anlass), kontakt NULL
  (Freitext), bemerkung NULL. **Blockiert nie** Trainings oder Spiele –
  `BookingService` behandelt eine überlappende Vermietung ausschließlich als
  Hinweis (`ConflictCheckResult::$hinweise`, nie `$conflicts`/`$warnings`),
  nicht als Konflikt oder bestätigungspflichtige Warnung. Anlegen/
  Bearbeiten/Löschen öffentlich (Ebene 2) als Events wie manuelle Spiele,
  Löschen = delete-Event; keine Konfliktprüfung beim eigenen Schreiben.
  **art** (Issue #63) unterscheidet Vermietung, Putzen und Sitzung: Zeitraum,
  Sportheim, Räume, Titel, Kontakt, Bemerkung und vor allem die
  Nicht-Blockade-Semantik sind für alle drei identisch, ein zweites Aggregat
  hätte nur dieselbe Logik dupliziert. Die Nicht-Blockade hängt am
  **Aggregat, nicht an der Art** – `art` wird in `VermietungService`
  (keine Konfliktprüfung) und `BookingService` (nur `$hinweise`) an keiner
  Entscheidungsstelle gelesen, sondern ausschließlich für Wortlaute. Alt-
  Events ohne Feld werden beim Replay deterministisch auf 'vermietung'
  gehoben (Upcasting analog `pitch.farbe`/`match.spielfrei`, Migration 017,
  DEFAULT = Upcast-Wert). Aggregat- und Tabellenname bleiben `vermietung`
  (Umbenennen wäre eine Migration ohne Gegenwert); in der **UI** heißt der
  Oberbegriff „Sportheim-Termin", die Arten tragen ihre eigenen Labels.
  Einzige Quelle aller art-abhängigen Texte ist das PHP-Enum
  `Domain\VermietungArt` (`label()` = Titel-Präfix, `hinweis()` = 🏠-Text);
  der Client bekommt sie über `appData.vermietungArten`
  (`PublicController::stammdaten()`), damit kein Wortlaut doppelt gepflegt
  wird und die Labels offline identisch sind. Frei konfigurierbare Arten
  (eigenes Aggregat wie `bereich`) sind bewusst NICHT umgesetzt – die
  String-Spalte verbaut den Weg nicht, eine spätere Migration könnte
  Strings → IDs heben wie Migration 013 es für `bereich` tat.
- **admin**: username UNIQUE, password_hash
- **event**: siehe Abschnitt 4 – Quelle der Wahrheit.
- **setting** (key/value): Konfiguration in der DB, nicht in Dateien
  (landet so im Backup). U. a. Auswärts-Farbe, Nutzungszeiten, Update-Kanal,
  Admin-Mail, App-Name/App-Name-Kurz (Issue #62), Spielfrei-Begriffe/
  Spielfrei-Farbe (Issue #65, kommagetrennte Begriffsliste statt eigenem
  Aggregat – im Unterschied zu venue_begriff global und ungeordnet, der
  erste Treffer genügt).

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
  Läuft **komplett unter dem Wartungsmodus** (`MaintenanceMode`, Abschnitt
  2/10): `start()` setzt das Flag VOR dem Anlegen der Schatten-Tabellen,
  `step()` nimmt es erst NACH dem Tausch zurück. Ohne diese Klammer verliert
  der Rebuild lautlos Schreibvorgänge – der Replay liest Events bis zum
  letzten Batch, tauscht dann die Tabellen, und alles, was der Schreibpfad in
  der Zwischenzeit auf die LIVE-Tabelle angewendet hat, verschwindet mit ihr.
  Das Event bleibt im Log, die Projektion weicht aber still vom Log ab, bis
  jemand zufällig erneut rebuildet; das Fenster ist klein, genau deshalb
  fiele es nie auf. Der Freeze selbst wirkt eine Ebene höher (der
  Docroot-Shim beantwortet alles außer `/admin` mit 503, und der öffentliche
  Schreibpfad liegt unter `/api`) – im Schreibpfad selbst steht dafür keine
  Zeile. Gegenstück ist `cancel()` (Schatten-Tabellen droppen, Statusdatei
  löschen, Flag weg, Live-Projektionen unberührt): ein abgebrochener Rebuild
  ließe die Instanz sonst dauerhaft im Wartungsmodus stehen.
- **DSGVO**: IPs in Events nach 90 Tagen anonymisieren (Cron, Setting);
  Zweck Missbrauchsabwehr steht in der Datenschutzerklärung.

## 5. Zugriffsmodell

1. **Lesen**: öffentlich, keine Session.
2. **Ändern (öffentlich)**: `editor_name` aus localStorage, wird bei jedem
   Schreib-Request mitgesendet; Server lehnt Schreiben ohne Namen ab, prüft
   ihn nicht weiter (Vertrauensmodell). „Nicht weiter geprüft" meint die
   **Identität**, nicht das Feld: Länge (max. `EventContext::
   MAX_EDITOR_NAME` = 100, die Breite von `event.editor_name`) wird sehr
   wohl validiert – im `$publicWrite`-Guard als 422 und in
   `EventStore::append()` als Invariante, genau aufgeteilt wie die
   bestehende Leer-Prüfung. Ohne sie ließe ein zu langer Name den ganzen
   Schreibvorgang im „Data too long" der Spalte auflaufen (Strict Mode),
   also als nacktes 500 statt als Feldfehler. Absicherung: Event-Historie +
   Rate-Limit pro IP (~30 Schreibzugriffe/Minute).
3. **Admin**: username + password_hash, PHP-Session. **Bootstrap-Regel**:
   Credentials aus config.php gelten NUR bei leerer admin-Tabelle; erster
   Login erzwingt Anlage eines echten Admins. **Brute-Force-Schutz**:
   `POST /admin/login` und `/admin/setup` sind pro IP gedrosselt
   (`RateLimiter::loginLocked/registerLoginFailure/resetLogin`, strikter als
   der öffentliche Schreibpfad – `LOGIN_LIMIT_PER_MINUTE`); es zählen NUR
   fehlgeschlagene Versuche, ein erfolgreicher Login/Setup setzt den Zähler
   zurück. Der Zähler liegt in derselben `rate_limit`-Tabelle unter einem
   gehashten `login:`-Schlüssel (kollidiert nie mit dem Schreib-Rate-Limit
   derselben IP). Die Sperrmeldung ist bewusst generisch (keine
   User-Enumeration). Durchsetzung im `AuthController` (nicht im generischen
   `$guard`), weil nur dort Erfolg/Fehlschlag bekannt ist.

CSRF-Token für alle Schreibrouten. Das Admin-Session-Cookie trägt
`httponly`, `samesite=Lax` **und `secure`** – letzteres aus dem Schema des
laufenden Requests abgeleitet (`Request::httpsFromGlobals()`, dieselbe
Quelle wie `UpdateController::baseUrl()`), nicht hart auf `true`: HTTPS ist
zwar Installationsvoraussetzung (setup.php-Checkliste), die Docker-Dev-
Umgebung läuft aber auf `http://localhost:8080`, wo ein secure-Cookie
lautlos verworfen würde und jeder Login zurück aufs Formular fiele. Ein
Host, der `$_SERVER['HTTPS']` gar nicht setzt, bekommt damit den bisherigen
Zustand – nie ein kaputtes Cookie. **Passwörter nie loggen**: der globale
Error-Handler (`Http/Kernel`) loggt nie den vollen Exception-String (ein
Stacktrace trägt bei `zend.exception_ignore_args=Off` die Funktionsargumente,
z. B. das Klartext-Passwort aus dem Login-Pfad), sondern nur
Exception-Klasse, `getMessage()`, Request-Methode/-Pfad und Fehlerort; die
php.ini des Shared-Hosting-Anbieters setzt zusätzlich `zend.exception_ignore_args=On` (Defense in
Depth). Farbwerte, die in den öffentlichen `<style>`-Block fließen, werden
an der AUSGABE gegen `Palette` gefiltert (nicht nur beim Schreiben) – eine
per Event-Korrektur eingeschleuste Nicht-Palette-Farbe kann so nicht aus dem
Style-Element ausbrechen.

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

- Der Cronjob des Hoster-Kontrollpanels ruft `bin/import_ics.php` alle 10 Min per HTTP auf
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
  Der Bestand der Quelle wird dabei **einmal je Lauf** geladen
  (`MatchRepository::findBySource()`) und nach `ics_uid` indiziert, statt je
  Feed-Eintrag einzeln abzufragen; der Absage-Nachlauf iteriert denselben
  Bestand weiter, statt ein zweites Mal zu lesen. Ergebnis und Reihenfolge
  sind identisch (jede in diesem Lauf geschriebene Zeile trägt eine UID aus
  dem Feed und wird vom Nachlauf ohnehin übersprungen; unberührte Zeilen
  lesen sich vor und nach den Schreibvorgängen gleich). Wichtig dabei: der
  Bestand wird **nach jedem Schreibvorgang fortgeschrieben** (`['id' => …,
  …$payload]` – die Payload-Schlüssel sind die Spaltennamen). Ein Feed darf
  eine UID wiederholen (`IcsParser` fasst `RECURRENCE-ID`-Overrides nicht
  zusammen); gegen einen eingefrorenen Schnappschuss liefe der zweite
  Treffer in ein zweites Insert und damit in
  `UNIQUE(import_source_id, ics_uid)` – die ganze Quelle fiele aus. Der
  frühere Einzel-Lookup verdeckte das, weil er jedes Mal neu las.
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
- **Spielfrei-Erkennung** (Issue #65): ein Feed-Termin ist spielfrei statt
  ein Spiel, wenn BEIDE Bedingungen gelten – leere LOCATION UND ein Treffer
  aus dem Setting `spielfrei_begriffe` (kommagetrennt, case-insensitive,
  mehrere erlaubt) im rohen SUMMARY; eine der beiden Bedingungen allein
  genügt nicht (ein Auswärtsspiel ohne gepflegte LOCATION ist kein
  Spielfrei). Anders als der Platz-Reflow wird `spielfrei` bei JEDEM Lauf
  für JEDEN Feed-Eintrag neu abgeleitet, auch für vergangene: die Flagge
  klassifiziert unveränderlichen Feed-Inhalt, ihre Neuableitung korrigiert
  also eine Fehlinterpretation statt Historie umzuschreiben (anders als ein
  gespeicherter Platz, der protokolliert, wo tatsächlich gespielt wurde).
  Der Begriff steckt NICHT in `sync_hash`; die Skip-Bedingung prüft deshalb
  zusätzlich `spielfrei === gespeichertes spielfrei`, analog der
  Zusatzklausel beim Platz-Reflow – eine reine Begriffs-Änderung erreicht
  so trotz gleichem Hash jede betroffene Zeile, ein zweiter Lauf danach ist
  idempotent. Ein manuelles Spiel kann nie spielfrei sein (Pflicht: Platz
  ODER ort_text, die leere-LOCATION-Bedingung greift dort also nie).
- **Spielfrei-Zeit** (Issue #78): der Feed liefert das Spielfrei als
  **DATE-TIME** (getakteter VEVENT) zu später Abendstunde (~23:59) am echten
  Tag, NICHT als `VALUE=DATE` – belegt dadurch, dass `IcsParser::
  parseDateTime` ein echtes DATE bereits sauber auf Mitternacht Europe/Berlin
  hebt (kein Versatz möglich); der beobachtete Uhrzeit-Effekt kann also nur
  aus einer echten Uhrzeit stammen. Der Import speichert diesen rohen
  `anstoss` bewusst **unverändert** (keine Normalisierung im Schreibpfad –
  das würde `anstoss` im `sync_hash` an die Spielfrei-Erkennung koppeln und
  bestehende Byes umschreiben). Der maßgebliche Kalendertag = `date(anstoss)`
  (der Tag des Anstoßes; **nicht** `date(effectiveEnd)` – der `+2h`-Fallback
  läge bei einem 23:59-Anstoß am Folgetag und schöbe den Termin einen Tag zu
  spät) und wird erst zur Anzeige (`EventSerializer`) bzw. im ICS-Export
  abgeleitet.
- Fehler pro Quelle isolieren; Fehlertext in import_source, Anzeige im Admin.

## 7. Anzeigemodi, Farben, Filter

- `GET /api/events?von=&bis=&typ=&team=&bereich=&venue=` liefert IMMER beide
  Farbfelder (`team_farbe`, `venue_farbe`) + `venue_id`, zusätzlich
  `pitch_farbe` und `pitch_kuerzel` (beide NULL ohne zugeordneten Platz,
  z. B. Auswärtsspiel). Auch `/api/verfuegbarkeit` und das Offline-Bundle
  liefern Platzfarbe und -kürzel mit. **Jeder Termin (außer Sperrungen)
  zeigt Team-, Platz- UND Spielstättenfarbe gleichzeitig als drei Farbpunkte,
  fest in dieser Reihenfolge** vor dem Titel, in jeder Ansicht und Breite
  inkl. Terminliste (Issue #39 führte die ersten zwei ein, ersetzte den
  früheren Team/Spielstätte-Umschalter; der Platz-Punkt kam später als
  dritter dazu und gilt seither unabhängig von `platzFarbDarstellung()`
  – Hintergrundfarbe in Tag/Woche bzw. die Ressourcen-Spalte bleiben davon
  unberührt, der Punkt ist zusätzlich, kein Ersatz; kein neuer Request, da
  alle drei Farbfelder bereits im Event-Payload liegen) – bei Auswärts-
  spielen liefert `venue_farbe` bereits die Auswärtsfarbe, kein Sonderfall
  im Frontend nötig; Auswärtsspiele/Spielfrei haben keine `pitch_id` und
  damit keinen Platz-Punkt. Sperrungen haben kein Team und bleiben bei ihrer
  bestehenden Art-Farbe (gesperrt/eingeschränkt). `venue_name` liegt seit dem
  Detail-Dialog-Ausbau (Abschnitt 8) für ALLE Termintypen im Payload
  (`EventSerializer::belegung()`/`sperrung()` lieferten es zuvor nicht, nur
  `spiel()`/`vermietung()`) – reine Ergänzung, kein neues Feld für Spiel/
  Vermietung, keine Bundle-`format`-Erhöhung (additiv, Offline-Port
  `offline-events.js` zieht nach). `typ=belegung` liefert
  zusätzlich Heimspiele mit zugeordnetem Platz (Status ≠ abgesagt); sie
  erscheinen dort auf ihrem Platz. Spiele tragen `manuell`
  (true = `import_source_id IS NULL`) und ein effektives `ende` (explizite
  Spalte, sonst Anstoß + 2 Std.). Die zusammengeführte Kalenderseite (Issue
  #37, Abschnitt 8) fragt `typ=''` ab (alle Termintypen in einem Feed, ohne
  Duplikate); `typ=belegung`/`typ=spiel` bleiben als engere API-Filterwerte
  Teil der öffentlichen Schnittstelle, werden vom Frontend aber nicht mehr
  gesendet.
- **Ganztägige Spiele** (Issue #78): Spiele tragen `allDay` (bool). Für
  Spielfrei (`spielfrei=true`) ist `allDay=true`, und `start`/`ende` sind auf
  `<tag>T00:00:00` gesetzt (tag = `date(anstoss)`, s. Abschnitt 6) statt auf
  die rohe ~23:59-Uhrzeit; alle übrigen Spiele sind `allDay=false` mit ihrer
  echten Anstoß-/Ende-Zeit. Da der abgeleitete Tag = `date(anstoss)` ist,
  bleibt der Bye im selben Kalendertag wie sein roher `anstoss` – der
  `MatchRepository::findInRange`-Filter (auf `anstoss`) erfasst ihn also
  unverändert, ohne Sonderbehandlung des Fetch-Zeitraums; online und offline
  (`offline-events.js` `inKickoffRange` auf demselben serialisierten `start`)
  stimmen dadurch von selbst überein.
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
- **Alle Filter im Filter-Sheet sind Chip-Gruppen, kein `<select>`** (Issue
  #82): Team, Bereich, Spielstätte, Platz, Termintyp, Manuelle Termine und
  Sportheim-Termine folgen damit derselben Bedienung wie der Arten-Filter
  (Issue #63) – ein Tap wählt/wechselt/löscht direkt. `window.VKFilter.
  erzeugeChipRow`/`aktualisiereChipRow` (`filter.js`) sind die geteilte
  DOM-Mechanik dafür, gemeinsam genutzt von `kalender.js` und
  `verfuegbarkeit.js`; Optionsliste und Wiring je Filter bleiben in der
  jeweiligen Ansicht. Rein clientseitig wie zuvor – URL-Persistenz,
  Chip-Zeile der aktiven Abweichungen und Offline-Verhalten sind unverändert,
  nur die Eingabe im Sheet selbst wurde ersetzt. **Mehrfachauswahl** (Issue
  #86): Team, Bereich, Spielstätte und Platz sind – wie schon die Arten
  (Issue #63) – Mehrfachauswahl-Filter (mehrere Chips gleichzeitig aktiv,
  kommaseparierte Liste, z. B. „nur die Spiele zweier Teams"); ein Klick
  schaltet den jeweiligen Chip in der Liste um. Termintyp, Manuelle Termine
  und Sportheim-Termine bleiben dagegen Einfachauswahl (Alle/Ohne/Nur bzw.
  Alle/Spiel/Training sind sich gegenseitig ausschließende Zustände, keine
  Liste von Elementen) – dort setzt ein Klick auf den bereits aktiven Chip
  auf den Default (`''`) zurück, ein Klick auf einen anderen ersetzt die
  Auswahl, kein eigener „Alle"-Chip nötig.
- Platzfilter (`filter-pitch`, clientseitig, `/api/events` kennt ihn nicht,
  Mehrfachauswahl seit Issue #86): immer sichtbar (Issue #37). In den
  Ressourcen-Views (Tag/Woche, ab der Desktop-Sidebar-Schwelle ~1100 px)
  reduziert eine Platzauswahl die Platz-SPALTEN auf genau die gewählten
  Plätze (inkl. der synthetischen „Auswärts"- und, Issue #65,
  „Spielfrei"-Spalte für Spiele ohne `pitch_id`); in jeder anderen
  Kombination (Monat, Liste, schmale Tag-/Wochenansicht) filtert er
  stattdessen die Termine direkt. Ohne Platzauswahl trägt „Alle
  Plätze" die Platzfarbe als Ersatz für die fehlenden Spalten an den Termin –
  mit Platz-Kürzel (Fallback Platzname) als Text-Präfix vor dem Titel;
  Auswärtsspiele (nie eine `pitch_id`) bilden dabei die eigene Gruppe
  „Auswärts" mit der globalen Auswärtsfarbe, Spielfrei-Termine (ebenfalls nie
  eine `pitch_id`, Issue #65) analog die eigene Gruppe „Spielfrei" mit der
  Spielfrei-Farbe – beide dürfen nicht ineinander aufgehen, obwohl beide
  `pitch_id IS NULL` haben. **Zusätzlich zur Darstellung** (Issue #57, eine
  Entscheidungsstelle:
  `VKKalenderPitch.platzFarbDarstellung(modus, hatResourceSpalten, pitchFilter)`)
  färben Tag/Woche ohne Ressourcen-Spalten den Termin-HINTERGRUND in
  Platzfarbe; das ist additiv zum Platz-Farbpunkt (s. o.), der in JEDER
  Ansicht unabhängig von `platzFarbDarstellung()` erscheint – Quadrat, eigene
  Form je Bedeutung wie in der Legende (Issue #38). `dayGridMonth` rendert
  zeitgebundene Termine als Dot-Events ohne Block-Fläche – ein Hintergrund
  kommt dort nicht an, dort bleibt es folglich beim Farbpunkt, der eigene
  `eventContent` ersetzt zudem FullCalendars eigenen Punkt. Die Terminliste
  (`listNachlade`, per Umschalter jederzeit erreichbar, Issue #37) ist kein
  Ressourcen-Ersatz, sondern ein chronologischer Feed: dort bleibt der
  Hintergrund neutral (Issue #40) – Team-/Platz-/Spielstättenfarbe zeigen
  dort wie überall die drei Farbpunkte, unabhängig von „Alle Plätze"; der
  Platz-Kürzel-Präfix im Titel bleibt in allen Darstellungen unberührt.
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
  Sperrungen. Der **Doppelbelegungs-Marker** (⚠, Abschnitt 3) folgt
  derselben Regel aus demselben Grund – ebenfalls in `eventContent`/
  `eventDidMount` abgeleitet (`public/js/doppelbelegung.js`,
  `findeUeberschneidende()`) statt im Datensatz vorberechnet –, unterscheidet
  sich aber bewusst in EINEM Punkt von Platzfarbe/-Präfix: die Quelle ist der
  UNGEFILTERTE geladene Bestand (`alleTermineAktuell`), nicht der bereits
  filterbereinigte wie beim 🏠-Vermietungshinweis
  (`vermietungenAktuell`/`findeUeberschneidende` in `vermietung-hinweis.js`)
  – eine Doppelbelegung darf nicht verschwinden, nur weil ein Team-/
  Bereichs-/Platzfilter gerade den Partner-Termin ausblendet.
- Filter „manuelle Termine" (`filter-manuell`, dreistufig: Alle / Ohne
  manuelle / Nur manuelle): clientseitig wie der Platzfilter, `/api/events`
  kennt ihn nicht; er wirkt auf das `manuell`-Flag im Event-Payload und
  funktioniert dadurch offline identisch. „Nur manuelle" blendet dabei auch
  Trainings/Sperrungen aus (Label macht das klar).
- **Vermietungen** (Issue #36) sind ein eigener Termintyp (`typ=vermietung`),
  ausschließlich im zusammengeführten Feed (`typ=''`) enthalten – nie unter
  `typ=belegung`/`typ=spiel`; ein aktiver Team-/Bereichsfilter blendet sie
  aus (kein Team), ein Venue-Filter matcht über die Spielstätte des
  Sportheims. Payload trägt `art` (Issue #63), `sportheim_id`,
  `sportheim_name`, `raum_ids`,
  `raum_text` (Kürzelliste, leer → „gesamtes Sportheim"), `kontakt`,
  `bemerkung`, kein `team_id`/`pitch_id`; `titel` ist serverseitig mit dem
  Art-Label präfigiert („Putzen: <Anlass> (<Räume>)"), damit das Offline-
  Bundle – das Vermietungen fertig serialisiert ausliefert – ohne eigenen
  Port dieselbe Beschriftung zeigt. Trainings/Belegungen/Sperrungen/
  Spiele tragen zusätzlich `pitch_sportheim_id` (NULL ohne Sportheim-
  Zuordnung des Platzes), damit der Client Termine ohne Zusatz-Request gegen
  laufende Vermietungen abgleichen kann (Hinweis-Indikator, Abschnitt 8).
  Filter „Sportheim-Termine" (`filter-vermietung`, dreistufig wie
  `filter-manuell`): clientseitig, `/api/events` kennt ihn nicht; „Nur
  Sportheim-Termine" blendet auch Trainings/Spiele/Sperrungen aus.
  Daneben der Art-Filter (Issue #63, `art`, Chip-Mehrfachauswahl
  kommasepariert, `''` = alle Arten, Container `#filter-art-chips`). Er ist eine reine
  **Teilmengen-Einschränkung auf Sportheim-Termine**: Trainings, Spiele und
  Sperrungen passieren ihn unverändert, sonst würde eine Art-Auswahl den
  ganzen Kalender leerräumen. Bei Stufe „Ohne" ist er gegenstandslos und die
  Chip-Reihe wird ausgeblendet. Eigener Filter statt weiterer Stufen von
  `filter-vermietung`, damit dessen Werte `''`/`'ohne'`/`'nur'` ihre
  Bedeutung behalten – **geteilte Alt-Links wirken dadurch strukturell
  unverändert** (`filter.js` fällt für einen fehlenden Parameter auf den
  Default zurück, `art=''` heißt „alle Arten"), ohne Sonderfall-Code.
- Filter „Termintyp" (Issue #56, `filter-typ`, dreistufig: Alle / Nur Spiele /
  Nur Trainings): clientseitig wie manuell/vermietung, `/api/events` kennt ihn
  nicht (Offline-Parität, Ressourcen-Spalten und Nachlade-Cache bleiben
  unberührt). Wirkt auf das `typ`-Feld im Event-Payload (`spiel`/`belegung`);
  beide Stufen blenden dabei auch Sperrungen und Vermietungen aus. UND-
  verknüpft mit den übrigen Filtern (z. B. „Nur Spiele" + „Nur manuelle" = nur
  manuell angelegte Spiele). Liefert eine Kombination kein Ergebnis, ersetzt
  ein Hinweistext mit den aktiven Filternamen die sonst stumm leere Ansicht
  (`#kalender-leer-hinweis`, aus den bereits geladenen/gefilterten Events pro
  Darstellung abgeleitet - kein Zusatz-Request).
- Spielstätten-Auflösung zur **Anzeigezeit** im einen `VenueMatcher`-Service
  (Anzeige UND Import): Spielfrei (Issue #65, am `spielfrei`-Feld erkannt,
  s. Abschnitt 6) → eigene Kategorie OHNE Venue-Auflösung, geht der Kette
  voraus; sonst erster `venue_begriff` nach sortierung, case-insensitive in
  `ort_text` → venue + Farbe; kein Treffer, aber Platz zugeordnet → Venue des
  Platzes (ein Spiel auf einem Platz ist per Definition an dessen
  Spielstätte, z. B. manuelles Spiel mit leerem ort_text); sonst → auswärts
  (Setting-Farbe). `VenueMatcher` selbst kennt nur Begriff→Venue und bleibt
  unverändert – die Kategorie-Entscheidung fällt in `EventSerializer::spiel()`.
- Filter: `team=<id>`, `bereich=<id>` (numerische Bereichs-ID; alte geteilte
  Links mit dem früheren Enum-String G/F/E/D/C/Herren funktionieren
  übergangsweise weiter – aufgelöst über `bereich.kuerzel`, Issue #27),
  `venue=<id>`, `venue=heim`, `venue=auswaerts`, `venue=spielfrei` (Issue
  #65 – ein spielfreier Termin hat ebenfalls `venue_id=null`, `venue=
  auswaerts` schließt ihn deshalb explizit aus). **Mehrfachauswahl** (Issue
  #86): `team`/`bereich`/`venue` akzeptieren eine kommaseparierte Liste von
  Werten, ein Termin matcht, wenn IRGENDEINER trifft (`team=5,7` zeigt z. B.
  nur die Spiele zweier Teams) – ein einzelner Wert (auch ein alter
  geteilter Link) verhält sich exakt wie zuvor, `EventFeedService::
  splitFilterList()` ist die geteilte Tokenisierung dafür. Mehr-Team-Slots
  matchen zusätzlich, wenn EIN Team des Slots EINEN der Filter-Werte erfüllt;
  API liefert `team_ids` zusätzlich zu `team_id` (= erstes Team, bestimmt
  Farbe).
- Belegungen tragen zusätzlich `intervall_wochen` (Rhythmus der Serie,
  Abschnitt 3). Kein reines Anzeigefeld: `startEdit()` baut den Prefill des
  Bearbeiten-Dialogs aus den Event-Props, ohne das Feld setzte jedes
  Bearbeiten einer 14-tägigen Serie sie stillschweigend auf wöchentlich
  zurück. Der Detail-Dialog zeigt es in der „Serie"-Zeile („Di, alle 2
  Wochen, 01.08.2026 bis 30.06.2027"), bei Intervall 1 unverändert ohne
  Zusatz.
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
  synthetischen „Sportheim"-Spalte (Issue #36) für Sportheim-Termine (die
  keinen Platz, sondern ein Sportheim betreffen; Issue #63: EINE Spalte für
  alle Arten – die Spalten bilden Orte ab, nicht Anlässe) und einer synthetischen
  „Spielfrei"-Spalte (Issue #65) für spielfreie Termine – auch sie haben
  keine `pitch_id`, dürfen aber nie in der Auswärts-Spalte erscheinen (ein
  Event mit unbekannter `resourceId` wird von FullCalendar in
  Ressourcen-Views sonst lautlos verworfen); Monat und Liste haben nie
  Spalten. **Spielfrei ganztägig** (Issue #78): Byes tragen im Feed
  `allDay=true` (Abschnitt 7), `toFcEvent` reicht das an FullCalendar durch;
  `allDaySlot` ist aktiv (`allDayText: 'ganztägig'`), sodass Tag/Woche das
  Spielfrei in der **All-Day-Zeile unter der Kopfzeile** zeigen (nicht im
  Stundenraster; in Ressourcen-Views in der All-Day-Zelle der
  „Spielfrei"-Spalte), Monat als normalen Tages-Eintrag und die Liste am
  Tagesanfang ohne Uhrzeit (der Zeit-Block in `eventContent` hängt an
  `arg.timeText`, das FullCalendar für Ganztags-Events leer lässt). Team- und
  Spielfrei-Farbpunkt plus Text-Label „<Kürzel>: Spielfrei" bleiben wie in
  #65 – „Farbe ist nie das einzige Signal" erfüllt. Der Button „+ Eintragen" öffnet ein Auswahl-Sheet („Belegung
  eintragen" / „Spiel eintragen" / „Sportheim-Termin eintragen") statt
  getrennter Toolbar-Buttons – EIN Eintrag für alle Arten (Issue #63), die
  Art wählt ein Segmented Control im Formular selbst (Default „Vermietung",
  serverseitig aus dem Enum gerendert). Sportheim-Termine zeigen als Termin
  nur den Spielstätten-Farbpunkt (kein Team) mit Text-Label „<Art>: <Anlass>
  (<Räume>)" und tragen zusätzlich die Klasse `ev-art-<art>`; der gedeckte
  Grund-Look bleibt typ-basiert, unterschieden wird über das **Text-Label**
  (drei weitere Farbtöne würden mit Team-/Spielstätten-/Platzfarben
  konkurrieren, und „Farbe ist nie das einzige Signal" ist so erfüllt).
  Trainings/Spiele auf einem Platz eines gerade belegten
  Sportheims tragen zusätzlich einen dezenten 🏠-Indikator, der volle
  Hinweis („<Art-Hinweis>: <Anlass>, Nutzung ggf. eingeschränkt" – z. B.
  „Sportheim wird gereinigt", Wortlaut aus `appData.vermietungArten`)
  steht im Detail-Dialog (`public/js/vermietung-hinweis.js`, reiner
  Overlap-Abgleich auf den bereits geladenen Events, kein Zusatz-Request;
  der Abgleich selbst kennt die Art nicht – er hängt an Sportheim und
  Zeitraum, die Art bestimmt nur den Wortlaut beim Rendern).
- **Detail-Dialog mit Farbpunkten**: Team-/Spielstätten-/Platz-Zeilen
  (`showDetail()`, `kalender.js`) tragen denselben Farbpunkt wie der Termin
  selbst und die Legende (Kreis=Team, Quadrat=Platz, Dreieck=Spielstätte,
  dieselbe `punkt()`-Hilfsfunktion wie `eventPunkte()`) statt reinen Texts –
  Farbe ist nie das einzige Signal, das Label bleibt daneben stehen. Alle
  vier Formen (inkl. Sportheim-Raute, s. u.) sind damit auf einen Blick
  unterscheidbar, auch wenn Team-, Spielstätten-, Platz- und Sportheimfarbe
  zufällig gleich oder ähnlich ausfallen. Ein Mehr-Team-Slot (gemeinsames
  Training, Abschnitt 3) zeigt dabei vor JEDEM Teamnamen dessen EIGENEN
  Farbpunkt (`zeileTeams()`, über `team_ids` und `appData.teams` aufgelöst –
  der Payload selbst trägt nur `team_farbe` des ersten Teams), nicht nur
  einen Punkt vor dem gesamten zusammengesetzten `team_name` ("E1 + E2").
  Jeder Termin mit einem Platz zeigt
  zusätzlich dessen Spielstätte als eigene Zeile (auch Training/Sperrung,
  nicht nur Spiel/Vermietung); ist dem Platz ein Sportheim zugeordnet
  (`pitch_sportheim_id`), erscheint eine zusätzliche Sportheim-Zeile mit
  eigenem Punkt (Raute wie `.legende-punkt-heim`, neue Klasse
  `.ev-punkt-heim` – nur im Detail-Dialog verwendet, nicht am Termin selbst)
  in der Farbe von dessen Spielstätte (Sportheime haben noch keine eigene
  Farbe, Issue #36/#47). Name und Farbe des Sportheims löst der
  Client über `appData.sportheime`/`appData.venues` auf (keine
  Sportheim-Zuordnung im Belegungs-/Spiel-/Sperrungs-Payload selbst, nur die
  ID) – analog `legende-gruppierung.js::raeumeNachSportheim()`.
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
- **„Jetzt gerade"-Banner**: eigenes Element `#kalender-laufend` ganz oben
  auf der Kalenderseite (vor der Titelzeile), zeigt die gerade laufenden
  Termine (Start ≤ jetzt < Ende, Berührung zählt als vorbei) an – bewusst
  `hidden`, solange nichts läuft, damit die Seite sonst unverändert
  aussieht. Eigener, von der sichtbaren Darstellung UNABHÄNGIGER
  `/api/events`-Abruf für „heute" (`fetchTagesEventsUngefiltert`,
  `public/js/kalender.js`) statt aus dem gerade geladenen Grid-/
  Listen-Bestand abgeleitet – sonst verschwände die Anzeige, sobald man in
  einen anderen Monat/eine andere Woche navigiert, obwohl „jetzt" davon
  unberührt weiterläuft; eigener Offline-Fallback aufs Bundle (wie
  `fetchEventsRange`), aber bewusst OHNE die aktiven Team-/Bereichs-/
  Platzfilter zu übernehmen – der Live-Status soll nicht verschwinden, nur
  weil gerade ein Filter aktiv ist (analog `alleTermineAktuell`/
  Doppelbelegung). Minütlicher Refresh (`setInterval`, kein Live-Ticker
  nötig für einen Status-Überblick). Reine Filter-/Sortierlogik in
  `public/js/kalender-laufend.js` (`laufendeTermine()`, testbar mit
  `node --test tests/js`, abgesagte Spiele zählen nicht als laufend);
  Klick auf einen Eintrag öffnet denselben Detail-Dialog wie ein
  Kalender-Termin.
- **Terminliste mit Nachladen**: `listNachlade` ist eine der vier
  Darstellungen (Issue #37, per Umschalter erreichbar, nicht mehr an eine
  Ansicht/Bildschirmbreite gebunden); ihr sichtbarer Bereich beginnt beim
  Wechsel dorthin standardmäßig bei **„heute"** als oberstem Tag (Issue #81).
  Bis Issue #81 begann sie stattdessen bewusst am Wochenanfang (Montag) der
  laufenden Woche (Issue #26: sonst fehlten beim ersten Öffnen bereits
  vergangene Tage der aktuellen Woche) – diese Absicht ist seit Issue #81
  über den Schalter „Vergangenheit anzeigen" (oberhalb der Liste) gewahrt:
  die vergangenen Tage der laufenden Woche sind einen Tap entfernt statt
  automatisch sichtbar. Nach vorne zeigt sie initial mindestens den
  kompletten nächsten Monat und lädt beim Scrollen ans Listenende weitere
  Batches nach (`von`/`bis` wächst schrittweise, die API kennt keine
  Pagination). Client-seitiger Cache dedupliziert nach Event-`id` (spätester
  Stand gewinnt, z. B. bei einer verlegten Partie); aktive Filter setzen
  Cache und Bereich auf den initialen Monat zurück – der
  Vergangenheits-Schalter bleibt dabei unangetastet in seinem gewählten
  Zustand (kein Filter, ein eigener, in localStorage gemerkter Zustand).
  Reine Frontend-Logik (`public/js/nachlade.js`, unit-getestet mit
  `node --test tests/js`).
  **Abbruch und Lücken nach vorne** (Issue #52): Das Ende der Kette wird NIE
  aus leeren Batches abgeleitet – maßgeblich ist allein `naechster` aus der
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
  **Verlorener Sentinel-Trigger bei kurzen Listen** (Issue #87, dieselbe
  Wurzel wie #46, ein weiterer Fall): ein IntersectionObserver-Callback
  feuert nur bei einem WECHSEL der Sichtbarkeit, nicht solange der Sentinel
  unverändert sichtbar bleibt. Bei einer eng gefilterten Liste (z. B. nur
  „Spielfrei") kann der untere Sentinel schon sichtbar werden, WÄHREND der
  allererste Batch noch lädt (`listeLaedt` bereits `true`) – `listeWeiterLaden()`
  bricht dann sofort ab, und da der Sentinel danach einfach sichtbar bleibt,
  feuert der Observer nie wieder; die Liste blieb bislang auf diesem ersten
  Batch hängen, obwohl `naechster` weitere Termine ankündigt. Derselbe
  Effekt trifft den oberen Sentinel, wenn „Vergangenheit anzeigen" NACH
  einer bereits abgeschlossenen Sichtbarkeits-Änderung eingeschaltet wird.
  Fix: der Observer-Callback hält zusätzlich einen laufenden
  Sichtbarkeits-Zustand (`listeSentinelSichtbar`/
  `listeVergangenheitSentinelSichtbar`) aktuell; `listeIndikatorSetzen()`/
  `listeVergangenheitIndikatorSetzen()` werten ihn nach JEDEM Ladevorgang
  erneut aus (nur auf Mobile – Desktop treibt seine Kette ohnehin
  unabhängig vom Sentinel bis zur Erschöpfung durch), statt auf einen
  weiteren Sichtbarkeits-Wechsel zu warten. Ein Reentrancy-Schutz
  (`listeWeiterLaedt`/`listeVergangenheitWeiterLaedt`, getrennt von
  `listeLaedt`/`listeVergangenheitLaedt`, das nur EINEN Batch-Fetch markiert)
  verhindert dabei einen doppelten Fetch derselben Range, falls dieser
  erneute Check innerhalb der eigenen laufenden `listeWeiterLaden()`-Kette
  feuert.
  **Vergangenheit per Schalter** (Issue #81): „Vergangenheit anzeigen"
  (Checkbox oberhalb der Liste, Touch-Ziel ≥ 44 px, Default „aus", Zustand
  in localStorage `kalender_liste_vergangenheit` gemerkt) lädt Termine VOR
  „heute" gebatcht nach OBEN nach – spiegelbildlich zum Nachladen nach
  vorne: `EventFeedService::vorherigerTermin()`/`vorherigerAnstossVor()` usw.
  (Repository-Methoden, `NextEventDate::ausSlotsVor()`/`spaeteste()`) sind
  das exakte Gegenstück zu `naechsterTermin()`/`ausSlots()`/`frueheste()` und
  liefern das Datum des letzten Termins VOR `von` als `vorheriger`-Feld
  neben `naechster` in derselben `/api/events`-Antwort; ein Lücken-Sprung
  funktioniert wie bei `naechster`, nur rückwärts
  (`vorherigeLadeGrenzen`/`vorherigeBatchGrenze` in `nachlade.js`). Die
  FullCalendar-View-Range der Liste reicht dafür technisch immer von einem
  fernen Vergangenheits- bis zum Zukunfts-Horizont; ob ein geladener
  Vergangenheits-Termin TATSÄCHLICH angezeigt wird, entscheidet – analog
  `platzFarbDarstellung()` (Issue #57) – ausschließlich eine reine
  Rendering-Funktion (`sichtbareListenEvents()`), NIE der Datensatz selbst:
  ein Aus-/Einschalten des Schalters in derselben Sitzung blendet bereits
  geladene Vergangenheit sofort um, ohne neu zu laden. Ein eigener Sentinel
  VOR `#kalender` im DOM (FullCalendar verwaltet `#kalender` selbst, ein
  Kind-Sentinel würde jeden Re-Render nicht überleben) löst per
  IntersectionObserver weitere Batches beim Scrollen an den oberen Rand aus,
  mit derselben „nach einem leeren Batch selbständig weiterladen"-Regel wie
  beim Nachladen nach unten (Issue #46). **Scrollanker**: neue Termine
  wachsen die Dokumenthöhe OBERHALB der aktuellen Scrollposition – ohne
  Korrektur springt der Viewport sichtbar nach unten; `scrollAnkerZiel()`
  (reine Funktion, `nachlade.js`) verschiebt `scrollY` um genau die neu
  hinzugekommene Höhe (`document.documentElement.scrollHeight`-Differenz vor/
  nach `calendar.refetchEvents()`), damit derselbe Termin optisch stehen
  bleibt. Ist wirklich nichts mehr davor, erscheint „Keine früheren
  Termine". Die Nutzung des Schalters zählt in usage_stat
  (`liste_vergangenheit`, `StatController`-Whitelist). Offline identisch:
  `vorherigerTermin()` ist als reiner Port in `public/js/offline-events.js`
  aus demselben Bundle berechnet (kein Zusatz-Request-Sonderweg), analog
  `naechsterTermin()` – wie dort bewusst NICHT paritätsgetestet (MIN/MAX-
  Abfrage serverseitig vs. Array-Scan clientseitig; verbindlich ist nur die
  Schranken-Eigenschaft, eine Abweichung kostet höchstens einen leeren
  Batch).
- Mobile-Patterns: Bottom-Sheets, Chip-Filter, Segmented Control,
  Touch-Ziele ≥ 44 px.
- **Legende** (Issue #38): EINE Komponente für Spielstätten-, Platz- und
  Team-Kürzel/-Farben (Teams gruppiert nach Bereich, dazu die globale
  Auswärts-Farbe und, Issue #65, die Spielfrei-Farbe als eigener Eintrag
  neben Auswärts – beide Sonderwerte sonst leicht verwechselbar, da beide
  keine echte Spielstätte haben; nur aktive Bereiche/Teams). Serverseitig
  gibt es dafür
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
  der Termin-Punkte (Issue #39): Team = Kreis, Platz = Quadrat, Spielstätte =
  Dreieck, Sportheim/Raum = Raute (Issue #47, eigene Form – Sportheime haben
  noch keine eigene Farbe, daher die Farbe ihrer Spielstätte) – vier klar
  unterscheidbare Formen, unabhängig davon, ob zwei Farben zufällig ähnlich
  ausfallen (vorher teilten sich Spielstätte und Platz dieselbe
  Quadrat-Form/-Klasse; seit der Detail-Dialog beide gleichzeitig zeigt,
  Abschnitt 8 oben, braucht jede Bedeutung ihre eigene Form). Das Dreieck
  entsteht per `clip-path` statt per `border-radius`: der zweischichtige
  Kontrast-Ring der übrigen Formen ist ein `box-shadow`, das der BOX folgt,
  nicht der geclippten Form – `.ev-punkt-venue`/`.legende-punkt-venue`
  bauen den Ring deshalb aus zwei zusätzlichen, größeren Dreieck-Flächen
  (`::before`/`::after`) unter einem echten Kind-Element für die Füllfarbe
  (`.ev-punkt-venue-fill`/`.legende-punkt-venue-fill`, von `punkt()` in
  kalender.js/legende.js erzeugt), Text immer
  daneben. Gruppe „Sportheime" (je Sportheim eingerückt seine Räume, nur
  aktive, in gepflegter `sortierung`) nutzt dieselbe `appData` wie
  Spielstätten/Plätze/Teams (`stammdaten()` liefert `sportheime`/
  `sportheimRaeume` bereits mit); ein Platz mit `sportheim_id` zeigt sein
  Sportheim zusätzlich als 🏠-Text in der Plätze-Gruppe. Ein
  Symbole-Abschnitt erklärt den ⚠-Doppelbelegungs-Marker (Abschnitt 3),
  den 🏠-Indikator an Terminen (Sportheim gerade vermietet), die
  Vermietungs-Darstellung (nur Spielstätten-Punkt, kein Team) und den
  Spielfrei-Punkt (Issue #65: kein Auswärtsspiel, für dieses Team ist an
  diesem Termin schlicht kein Spiel angesetzt). Die
  Sportheim-Termin-Arten (Issue #63) zählt der Abschnitt aus
  `appData.vermietungArten` auf, statt sie – wie zuvor den festen Text
  „Vermietung: <Anlass> (<Räume>)" – erneut zu hartcodieren.
- **PWA/Offline**: Service Worker cached App-Shell; `GET /api/offline-bundle`
  (format-versioniert, aktuell 8 – Issue #36 hat `sportheime`/
  `sportheim_raeume`/`vermietungen`-Listen ergänzt und `pitch.sportheim_id`
  aufgenommen, Issue #65 hat `spiele[].spielfrei` und
  `settings.spielfrei_farbe` ergänzt, Issue #63 `vermietungen[].art` plus den
  art-präfigierten `titel`, Issue #78 hat Byes ganztägig gemacht –
  `spiele[].allDay` plus Tages-Mitternacht in `start`/`ende` statt der rohen
  Uhrzeit –, der Slot-Rhythmus hat `slots[].intervall_wochen` ergänzt: ein
  älteres Bundle expandierte eine 14-tägige Serie wöchentlich und zeigte
  doppelt so viele Trainings) liefert den **kompletten Datenbestand**
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
  `/kalender`; der Service Worker cached die App-Shell-Seiten `/`,
  `/kalender`, `/verfuegbarkeit`, `/legende` (Issue #66: `/legende` rendert
  offline vollständig aus derselben `appData` wie online – dieselbe
  `[data-legende]`-Komponente wie Startseiten-Details und Kalender-Overlay,
  Abschnitt 8 oben) und mappt `/belegung`/`/spielplan` offline auf die
  gecachte Kalenderseite, damit alte Bookmarks funktionieren. **`/abonnieren`
  wird bewusst NICHT gecacht** (Issue #66): seine Abo-Links brauchen ohnehin
  eine Netzwerkverbindung. Der Navigationslink dorthin wird clientseitig
  deaktiviert, sobald `navigator.onLine` false ist (`public/js/app.js`, auf
  jeder Seite geladen – anders als `offline.js`, das nur auf Kalender/
  Verfügbarkeit läuft); ein direkter Aufruf offline bekommt vom Service
  Worker (Fetch-Handler-`catch`) eine eigene, im SW-Template eingebettete
  Hinweisseite statt eines Browserfehlers. Kein Bundle-`format`-Bump nötig
  (betrifft nur die App-Shell-Liste und rein clientseitige Navigation, keine
  Bundle-Shape).
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
  **Doppelbelegung** (Abschnitt 3): `AvailabilityCalculator::
  buildTimeline()` sammelt für ein `belegt`-Segment ALLE deckenden
  Belegungen statt beim ersten abzubrechen – `label` bleibt wie bisher die
  erste (Training vor Spiel in der Priorisierung), ein zusätzliches,
  additives `labels`-Feld (Liste aller Namen) erscheint NUR, wenn mehr als
  eine Belegung den Abschnitt deckt. Ohne diese Erweiterung hätte eine
  Teilüberlappung zweier Belegungen erfundene, nicht überlappende Grenzen
  gezeigt (der alte Fehler betraf bislang nur den seltenen Fall
  Spiel-über-Training); mit ihr trennt der Zeitstrahl den doppelt belegten
  Abschnitt sauber ab und zeigt ihn schraffiert + mit ⚠. Der Port
  `offline-verfuegbarkeit.js` hält das Verhalten byte-identisch
  (Parity-Fixtures, Abschnitt 11).
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
  Kindern selbst. `.ev-inhalt` erzwang seither `flex-direction: row
  !important` (wie die `--fc-event-*`-Variablen oben, Abschnitt 8 Beginn) –
  seit Issue #75 (s. u.) gilt die Zeile nur noch als Basis für Monat/Liste,
  ohne `!important`; für `.fc-v-event` (Tag/Woche) überschreibt eine
  spezifischere Regel bewusst wieder auf Spalte. Vollständiger Text bleibt
  über `title`/`aria-label` am `.ev-titel`-Element und den Detail-Dialog
  erreichbar.
- **Die Kürzungsreihenfolge braucht eine begrenzte Breite** (Issue #67 –
  die Regel aus #58 war unvollständig): `flex-shrink` schrumpft nur gegen
  eine Vorgabe. In Tag/Woche/Monat liefert die Grid-Geometrie sie (der
  Termin-Block bekommt seine Breite aus Spalte/Zelle), in der **Terminliste
  nicht**: FullCalendar rendert sie als `table.fc-list-table` mit
  `table-layout: auto`, wo sich die Spalten am INHALT sizen. Das
  `white-space: nowrap` von `.ev-titel-text`/`.ev-praefix` macht deren
  min-content-Breite gleich der vollen Textlänge, und diese Breite ist für
  eine Auto-Layout-Tabelle eine harte Untergrenze, die ungebremst nach oben
  wandert (Zelle → Spalte → Tabelle). `overflow: hidden` auf den Kindern
  stoppt das nicht – es begrenzt die Darstellung, nicht den Platzbedarf, den
  die Zelle nach oben meldet. Die Tabelle wurde damit breiter als ihr
  `.fc-scroller` (der nur vertikal scrollt, `overflow-x: visible`), der
  Überschuss lief bis zu `body`/`html` durch: der gemeldete Scrollbalken saß
  **am Dokument**, nicht am Termin und nicht am Scroller (bei 360 px:
  Tabelle 863 px in einem 343-px-Viewport). Die #58-Regeln griffen dabei
  durchaus – `flex-direction: row` und `min-width: 0` waren aktiv –, sie
  liefen nur ins Leere. Fix: `max-width: 0` auf
  `td.fc-list-event-title` deckelt den Beitrag der Zelle zur Spaltenbreite;
  die Spalte bekommt nur noch den Rest nach den beiden Inhalts-Spalten
  (Uhrzeit/Grafik, von FullCalendar per `width: 1px` + nowrap schmal
  gehalten), der Flex-Container hat endlich eine definite Breite und die
  Kürzungsreihenfolge wirkt. Bewusst **nicht** `table-layout: fixed`: das
  zieht die Spaltenbreiten aus der ersten Zeile, und FullCalendars `<thead>`
  hängt per `position: absolute; left: -10000px` außerhalb des Flusses –
  fixed fiele auf die erste Body-Zeile zurück (`.fc-list-day` mit
  `colspan=3`, also EINE Zelle) und teilte alle drei Spalten gleich breit
  auf. Da der Farbpunkt der Liste bei uns ohnehin ausgeblendet ist (die
  Punkte stehen im Titel), verliert die Grafik-Spalte zusätzlich ihre
  Polsterung – seit die Titel-Spalte nur noch den Rest bekommt, ginge die
  direkt vom Titel ab. Regressionsprüfung ist manuell (Prüfliste im PR zu
  #67: 320/360/430/768/>1100 px × Tag/Woche/Monat/Liste, Kriterium
  `document.documentElement.scrollWidth === clientWidth`) – die Ursache
  liegt in CSS-Layout, nicht in einer Entscheidungsfunktion, für die sich
  ein Unit-Test wie bei `platzFarbDarstellung()` (Issue #57) lohnen würde.
- **Termine in Tag/Woche stapeln Uhrzeit/Punkte/Team vertikal** (Issue #75):
  Auftrag war eine vertikale Darstellung statt der #58-Zeile – scheinbar ein
  Widerspruch, da #58 die Zeile gerade WEGEN Überlauf erzwang. Auflösung:
  #58s Überlauf kam von FullCalendars ungebremster `column`-Kippung ohne
  jede Breitenbegrenzung auf den Kindern; die neue Spalten-Regel
  (`.fc .fc-v-event .ev-inhalt{flex-direction:column;align-items:stretch}`,
  Spezifität 0,3,0 schlägt FCs `.fc-v-event .fc-event-main-frame` mit 0,2,0
  ohne `!important`) behält dagegen `min-width:0`+`overflow:hidden`+Ellipsis
  auf jedem gestapelten Kind bei – Uhrzeit oben (`flex-shrink:0` zusätzlich
  in der Höhe, damit sie nie verschwindet), Punkte in der Mitte (`.ev-punkte`
  bleibt `flex-shrink:0`, achsenunabhängig), Titel unten. Die #58-
  Kürzungsreihenfolge (Titel vor Präfix) bleibt unangetastet, weil
  `.ev-titel` selbst eine interne Row-Flex-Zeile bleibt – nur die drei
  äußeren Blöcke stapeln sich, nicht Präfix/Titel gegeneinander. Bei zu
  wenig Höhe (sehr kurze Termine) gibt der Titel zuerst nach und wird vom
  Container-`overflow:hidden` geklippt, voller Text bleibt über
  `title`/`aria-label`/Detail-Dialog erreichbar – dasselbe Prinzip wie #58,
  nur auf der Höhen- statt der Breiten-Achse. Monat (Dot-Events, kein
  `.fc-v-event`) und Liste (#67-Tabellenlayout) bleiben bei der Zeile,
  unverändert. Die Tagesansicht übernimmt dieselbe Spalten-Darstellung wie
  die Woche (gleiche `.fc-v-event`-Basis, Konsistenz statt eines
  Sonderfalls, zumal Tag die mobile Default-Ansicht ist).
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
- **App-Name** (Issue #62): Settings `app_name` (Pflicht, Default
  „Vereinskalender" – Bestandsinstallationen ändern sich beim Update nicht,
  Migration 015 hebt das zuvor ungenutzte Setting `vereinsname` auf den
  neuen Schlüssel) und `app_name_kurz` (optional, leer → `app_name` auf
  12 Zeichen gekürzt als `manifest.webmanifest`-`short_name`). Pflege im
  Admin bei den übrigen Einstellungen, direkt neben dem Vereinswappen.
  Verwendet in `<title>` (Suffix „ – App-Name" außer auf der Startseite,
  die den App-Namen allein zeigt), Kopfzeile/Footer, `manifest.webmanifest`
  (`name`/`short_name`), Push-Fallback-Titel (`__APP_NAME__`-Platzhalter in
  `sw.template.js`, analog `__VERSION__`), Alarm-Mail-Betreffen
  (`[App-Name] ...`) und `X-WR-CALNAME` der ICS-Feeds. Derselbe Hinweis wie
  beim Wappen: bereits installierte PWAs übernehmen einen neuen Namen erst
  bei Neuinstallation. `View` reicht `appName` global an beide Layouts
  weiter (analog `wappenVorhanden`), kein Bundle-`format`-Bump nötig (rein
  serverseitig bzw. im SW-Template verwendet, keine Bundle-Shape-Änderung).

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
6. **Abschluss**: **Docroot-Shim auffrischen** (s. u.), dann Selbsttest
   (Startseite + /api/events → 200), die letzten 2 Releases behalten.

**Docroot-Shim / Selbstheilung**: `web/index.php` ist die einzige Datei, die
kein Release-ZIP und kein `rename()` je anfasst – und damit die einzige, die
antworten kann, während `current/` gerade getauscht wird. Deshalb steht die
Wartungsmodus-Prüfung DORT (`ReleaseSwitcher::SHIM`) und nicht nur in
`current/public/index.php`: zwischen `rename(current, _prev)` und
`rename(neu, current)` existiert das Release kurz nicht, ein `require` der
fehlenden Datei wäre ein Fatal Error statt einer Wartungsseite. Der Shim
prüft daher BEIDES – fehlendes Release und gesetztes Flag. `UpdateService::
finish()` schreibt ihn bei Bedarf neu; das ist der erste Schritt, der bereits
auf dem NEUEN Release läuft (check/backup/download/extract und das
Umschalten selbst führen noch den Code der alten Version aus). Daraus folgt
prinzipbedingt: **das Update, das eine Shim-Änderung einführt, kann seinen
eigenen Umschaltvorgang nicht schützen, nur jeden danach.** Die Auffrischung
läuft VOR dem Selbsttest, damit dieser den frischen Shim tatsächlich über den
Webserver ausprobiert; schlägt er fehl, wird der vorherige Shim
zurückgeschrieben (ein kaputter Shim nähme die ganze Seite inkl. `/admin`
mit, ein Rollback wäre dann nicht mehr erreichbar). Ein nicht beschreibbarer
Docroot bricht das Update nicht ab, sondern erscheint als Meldung im
Update-Log. Der Shim-Inhalt existiert in drei Kopien (`ReleaseSwitcher::
SHIM`, `bin/setup.template.php`, `docker/web/index.php`); `ShimContentTest`
erzwingt Byte-Gleichheit und prüft die Syntax per `token_get_all(...,
TOKEN_PARSE)` – `php -l` scheidet aus, weil auf dem Zielhosting kein
`exec()` verfügbar ist.

**Wartungsmodus** (`App\Service\MaintenanceMode`, `shared/maintenance.flag`):
gemeinsame Mechanik von Updater und Rebuild (Abschnitt 4). Die Flag-Datei
trägt Grund und Startzeitpunkt als JSON (Alt-Format „nur ISO-Zeitstempel"
wird gelesen – eine Instanz kann WÄHREND gesetztem Flag aktualisiert werden).
Weil sowohl ein abgestürztes Update als auch ein abgebrochener Rebuild das
Flag stehen lassen können, zeigt das Admin-Layout auf JEDER Seite ein Banner
mit Freigeben-Button (`POST /admin/wartung/aufheben`); vorher führte der
einzige Weg zurück über FTP, da `UpdateService::reset()` nur die Statusdatei
löscht, nie das Flag.

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

- Lokal: docker-compose mit PHP 8.5 + MySQL, produktionsnah zum
  Shared-Hosting-Anbieter (disable_functions=exec,shell_exec,…; realistische
  Limits); App läuft per
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
  **alle drei Lösch-Umfänge** analog (alle / ab hier mit Truncate-oder-
  Ganz-Löschen-Degradierung / nur dieser als slot_exception statt Löschen),
  inkl. Rückwärtskompatibilität ohne `edit_scope`, unbekanntem Umfang,
  Grenzfall „ab hier" auf der ERSTEN Occurrence der Serie (Guard muss
  Occurrence-basiert bleiben, nicht datumsbasiert), verwaister
  slot_exception nach „ab hier" + spätem „alle"-Löschen und deren
  Replay-Determinismus (kein Verwaister im Report);
  **Einzeltermin** (Issue #83: `BookingService::applyEinzeltermin()` leitet
  aus `modus=einzeltermin` + `datum_neu` einen Wochentag und
  gueltig_ab == gueltig_bis == diesem Datum ab, inkl. 1–7-Grenzfall
  So/Mo – `testCreateEinzelterminDerivesWeekdayFromDate` – und fehlendem
  Datum als Validierungsfehler; Expansion liefert für den erzeugten Slot
  genau eine Occurrence am richtigen Datum; Konfliktprüfung greift
  identisch zum Serienformular; Bearbeiten eines bereits bestehenden
  Eintages-Slots bleibt beim Umfang „alle" – Update in place ohne
  slot_exception/Split, `testUpdateEinzeltagesSlotInPlaceSkipsSplit`);
  **Slot-Rhythmus** (`intervall_wochen`, Abschnitt 3 – `SlotExpanderTest` +
  spiegelbildlich `tests/js/offline-events.test.js`: Intervalle 1–4 als
  Matrix; **`testIntervalAnchorIsIndependentOfRequestedRange`** als
  wichtigster Test – derselbe Ausschnitt direkt abgefragt muss dieselben
  Daten liefern wie aus einer großen Abfrage gefiltert, genau das bricht,
  wenn wieder auf `max($rangeStart, gueltig_ab)` verankert wird;
  `testIntervalKeepsAllWeekdaysInTheSameWeek` gegen ein Verschränken der
  Wochentage; DST-Pflichttests auch für den Intervall-Pfad, mit einer
  14-tägigen Serie, die genau auf dem Umstellungswochenende liegt; fehlendes
  Feld = wöchentlich, byte-gleich zum Verhalten vor dem Rhythmus; Ausnahmen
  greifen unverändert. `BookingServiceTest`: Validierung 0/-1/5/'abc'/'2.5'
  → `ValidationException`, fehlend → 1; **Split „dieser und alle folgenden"
  verschiebt keinen einzigen Termin** – die Daten beider Teile zusammen sind
  identisch mit denen der ungeteilten Serie –, Kürzen erhält den Takt des
  Rests, ein Eintages-Slot speichert immer 1 (auch wenn das Formular etwas
  anderes sendet), und zwei um eine Woche versetzte 14-tägige Serien auf
  demselben Platz kollidieren NICHT – der Fall, den eine wöchentliche
  Expansion fälschlich als Doppelbelegung meldete. `ReplayDeterminismTest`:
  Alt-Event ohne Feld → 1, explizit gesetzter Wert und ein späterer
  Takt-Wechsel überleben den Rebuild. `EventFeedTest`: der Belegungs-Payload
  trägt `intervall_wochen`, und die 14-tägige Serie fehlt in der Folgewoche,
  die wöchentliche nicht. Parity-Fixtures: ein eigener Slot mit Intervall 2
  plus die Fälle `zweiwochen-takt-an`/`-aus` (zwei aufeinanderfolgende
  Wochen); der Diff der älteren `events-*.json` ist rein additiv – nur das
  neue Feld, kein einziges geändertes Datum, was den Intervall-1-Pfad als
  regressionsfrei belegt);
  ICS-Sync (insert/update/skip/abgesagt, Verlegung per gleicher UID;
  **im Feed wiederholte UID** aktualisiert die im selben Lauf angelegte
  Zeile statt ein zweites Insert zu versuchen –
  `testRepeatedUidInOneFeedUpdatesInsteadOfInsertingTwice`; **Absage-Nachlauf
  im gemischten Lauf**, d. h. ein Eintrag wird aktualisiert WÄHREND ein
  anderer aus dem Feed verschwindet und abgesagt werden muss –
  `testCancelFollowUpStillSeesRowsThisRunDidNotTouch`, der Fall, den die
  übrigen Nachlauf-Tests mit ihrem leeren Feed nicht abdecken);
  VenueMatcher (Mehrfach-Begriffe, Priorität, case-insensitive, heim vs.
  auswärts); Konfliktprüfung (gesperrt blockiert, eingeschraenkt warnt,
  **Doppelbelegung** – Belegung-über-Belegung UND Belegung-über-Spiel warnen
  seit diesem Feature statt abzulehnen, symmetrisch zur bereits bestehenden
  Spiel-Seite; die Buchung wird trotz Warnung gespeichert, `gesperrt`
  blockiert unverändert auch bei gleichzeitiger Doppelbelegung; Berührung
  bleibt konfliktfrei; `BookingServiceTest`/`ManualMatchServiceTest`);
  Verfügbarkeitsberechnung (Lücken innerhalb Nutzungszeiten, **Doppelbelegung**
  – ein doppelt belegtes Zeitstrahl-Segment trägt ein additives `labels`-Feld
  mit allen Belegungsnamen statt nur das erste zu zeigen, geprüft für volle UND
  Teilüberlappung – `AvailabilityServiceTest`, Parity gegen
  `offline-verfuegbarkeit.test.js`);
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
  s. u.); **Sportheim-Termin-Arten** (Issue #63: die Nicht-Blockade-
  Regressionen laufen als `#[DataProvider]` über ALLE Arten – für jede muss
  Belegung UND Spiel ohne Bestätigungszwang speichern, `$conflicts`/
  `$warnings` leer, genau ein `$hinweise`-Eintrag mit `typ='vermietung'` und
  dem art-spezifischen Wortlaut; `art`-Upcasting beim Replay – Alt-Event ohne
  Feld → 'vermietung' –, eine explizit gesetzte Art überlebt den Rebuild
  unverändert, Art-Wechsel per Update ist replay-deterministisch; Schreibpfad
  create/update/delete je Art inkl. `art` im Delete-Vollbild, fehlende Art →
  'vermietung', unbekannte Art → `ValidationException`; Feed liefert `art`
  und den art-präfigierten `titel`; Verfügbarkeits-Hinweis-Layer enthält alle
  Arten, ohne je einen Platz zu blockieren; clientseitig die Art-Filter-
  Matrix inkl. der **Alt-Link-Regression** `?vermietung=nur`/`=ohne` ohne
  `art`-Parameter, und `findeUeberschneidende` greift für jede Art gleich);
  **Restriktionen bearbeiten** (Issue #64: Schreibpfad
  create/update/delete inkl. Event + Projektion in einer Transaktion,
  Art-Wechsel wirkt sofort auf die Konfliktprüfung – 'eingeschraenkt' →
  'gesperrt' blockiert die nächste Prüfung, ohne eine zuvor gespeicherte
  Belegung zu entfernen –, `betroffene`-Hinweisliste bei überlappenden
  Trainings-/Spielterminen sowohl bei create als auch bei update,
  Push-Auslösung nur bei Created/Updated nicht bei Deleted, Replay nach
  Update); **Bereich-
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
  nicht); **Terminliste Vergangenheit per Schalter** (Issue #81:
  `vorherigerTermin()`/`vorherigerAnstossVor()`/`vorherigerBeginnVor()`/
  `findGueltigVor()`/`ausSlotsVor()`/`spaeteste()` als exaktes Spiegelbild der
  Issue-#52-Gegenstücke – Lücken-Sprung rückwärts, laufende/berührende
  Termine „am Grenztag" zählen nicht als „vorheriger", Slot-Regel als
  spätester Kandidat, Filter heben die Schranke nicht an; Default „heute"
  oben in `tests/js/nachlade.test.js` sowie `tests/js/offline-events.test.js`
  (`vorherigerTermin()`-Port); Schalter ein → gebatchte Vergangenheits-Batches
  ohne Duplikate (Merge nach id); Abbruch nach hinten („keine früheren
  Termine" bei `vorheriger === null`); `scrollAnkerZiel()` als reine Funktion
  für den stabilen Scroll-Anker beim Einfügen oberhalb der Scrollposition);
  **Platzfarb-Darstellung** (Issue #57,
  `tests/js/kalender-pitch.test.js`: vollständige Matrix Darstellung × Breite
  × Platzfilter über `platzFarbDarstellung()`, komponiert mit
  `hatResourceSpalten()` – Hintergrund nur in Tag/Woche ohne Spalten, Punkt
  nur im Monat, „keine" in Ressourcen-Views/Liste/bei Einzelplatz, und
  derselbe Termin liefert je Darstellung ein anderes Ergebnis – die
  Eigenschaft, die die eingebackene Variante verletzte); **Mehrfachauswahl-
  Filter** (Issue #86: `EventFeedService::events()` matcht bei mehreren
  kommaseparierten `team`/`bereich`/`venue`-Werten, wenn IRGENDEINER trifft
  – `testMultiValueFiltersMatchAnySelectedValue` in `EventFeedTest.php`,
  inkl. gemischter Venue-Token wie `<id>,auswaerts` und pro Komma-Token
  aufgelöster Legacy-Bereichs-Enum-Strings; ein einzelner Wert bleibt
  regressionsfrei identisch zum Verhalten vor Issue #86); **Offline-
  Paritätstests** (Issue #25): goldene Fixtures
  (`tests/fixtures/parity/bundle.json` + `cases.json`, inkl. beider
  DST-Wochenenden, überlappender Slots, mehrtägiger vor dem Zeitraum
  beginnender Sperrung, sowie – Issue #36 – Sportheime/Räume/Vermietungen
  inkl. einer raumbezogenen und einer Ganzhaus-Vermietung, eines Platzes mit
  sportheim_id und eines eigenen `vermietung`-Falls, sowie – Issue #65 –
  eines isolierten Spielfrei-Falls in einer ansonsten leeren Woche (Issue #78
  auf die ganztägige Shape gehoben: `allDay=true`, `start`/`ende` =
  Tages-Mitternacht – der Diff dieses Falls belegt die additive
  `allDay`-Ergänzung), sowie –
  Issue #63 – eines eigenen `sportheim-termine`-Falls mit je einem Putzen-,
  Sitzungs- und Vermietungs-Termin an einem sonst leeren Tag; der ältere
  `vermietung`-Fall bleibt bewusst unangetastet, sein Diff belegt damit die
  rein additive `art`-Ergänzung bei unverändertem Titel) prüfen,
  dass die clientseitigen Ports
  (`public/js/offline-events.js`, `public/js/offline-verfuegbarkeit.js`)
  byte-identisch zur PHP-Referenz (`SlotExpander`/`EventSerializer`,
  `AvailabilityCalculator`) sind – `tests/Kalender/ParityFixturesTest.php`
  (PHPUnit, DB-frei) und `tests/js/offline-*.test.js` (`node --test
  tests/js`, Teil der CI) laufen gegen dieselben committeten
  `expected/*.json`; bei Algorithmus-Änderungen `generate.php` neu laufen
  lassen und den Diff bewusst reviewen.
- **Spielfrei-Erkennung** (Issue #65): Erkennung (leere LOCATION + Begriff im
  SUMMARY → spielfrei, nur eine der beiden Bedingungen erfüllt → kein
  Spielfrei, Groß-/Kleinschreibung, mehrere kommagetrennte Begriffe, leere
  Begriffs-Einstellung erkennt nichts – Regressionstest gegen den
  `mb_stripos($s, '')`-Fallstrick); Begriffs-Änderung klassifiziert
  bestehende Feed-Einträge beim nächsten Lauf neu, auch vergangene, dritter
  Lauf danach `skipped` (Idempotenz); `spielfrei`-Upcasting beim Replay
  (Alt-Event ohne Feld → false); `spielfrei` übersteht Platz-Zuordnung
  (`MatchService::assignPitch()`) und den Absage-Nachlauf; `venue=spielfrei`
  liefert nur Byes, `venue=auswaerts` liefert sie NICHT mehr; ein Bye
  erscheint nie unter `typ=belegung`; Verfügbarkeitsberechnung bleibt für
  Byes ohne Intervall und ohne Hinweis-Layer; ICS-Export enthält den Bye mit
  kanonischem Titel „<Kürzel>: Spielfrei", ohne LOCATION-Zeile.
- **Spielfrei ganztägig** (Issue #78): `EventSerializer::spiel()` liefert für
  Byes `allDay=true` und `start`/`ende` = Tages-Mitternacht des **Anstoß-
  Tages** (`date(anstoss)`), nicht die rohe ~23:59-Uhrzeit; alle übrigen
  Spiele `allDay=false` mit echter Zeit. Regressionstest gegen die
  Ende-Ableitung: ein Bye mit Anstoß 23:59 bleibt auf DEM Tag (nicht dem
  Folgetag, den `+2h` liefern würde) und taucht in einem Batch, der erst am
  Folgetag beginnt, NICHT auf. ICS-Export: Bye als `DTSTART;VALUE=DATE` mit
  exklusivem Folgetag-`DTEND`, keine Uhrzeit, keine LOCATION, Tag aus dem
  Anstoß. Offline-Parität: die ganztägige Bye-Shape fließt aus dem Bundle
  (format 7) unverändert durch `offline-events.js`.
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
