# Vereinskalender

Webkalender für einen Fußballverein: Sportplatz-Belegung (Training) und
Spielplan mit automatischem Import von fussball.de. Läuft auf Shared Hosting
bei einem Webhoster (PHP 8.5 + MySQL/MariaDB) – ohne SSH, ohne Composer auf
dem Server. Architektur und Konventionen: siehe [CLAUDE.md](CLAUDE.md).

**Funktionen:** eine Kalenderseite mit vier Darstellungen (Tag/Woche/Monat/
Liste) für Platzbelegung (wiederkehrende Trainingsslots mit Ausnahmen und
Platzsperrungen) und Spielplan (ICS-Import) gemeinsam, öffentliche
Verfügbarkeitsansicht, Kalender-Abos (ICS-Feeds), Web-Push bei Sperrungen und
Spielverlegungen, PWA mit Offline-Unterstützung, Event-Historie mit
Rückroll-Funktion, Self-Update, Backups, Saison-Assistent.

## Installation bei einem Shared-Hosting-Anbieter

1. **Vorbereiten im Kontrollpanel des Hosters**
   - Per FTP ein eigenes Verzeichnis mit einem `web`-Unterordner anlegen
     und die Subdomain/Domain im Kontrollpanel **auf den `web`-Unterordner** zeigen
     lassen. Die Anwendung legt ihre Daten eine Ebene über dem DocumentRoot
     ab (nicht per Browser erreichbar) – deshalb ist der Unterordner wichtig,
     sonst landen die Daten im FTP-Hauptverzeichnis:

     ```
     /kalender/              ← hier entstehen current/, releases/, shared/
        web/                 ← DocumentRoot der (Sub-)Domain
           setup.php         ← einzige hochzuladende Datei
     ```
   - PHP-Version der (Sub-)Domain auf **PHP 8.5** stellen.
   - MySQL-Datenbank anlegen, Zugangsdaten notieren.
2. **setup.php hochladen**
   - Vom [neuesten Release](https://github.com/MirkoSc/vereinskalender/releases)
     die Datei `setup.php` laden und per FTP in den `web`-Ordner legen
     (der einzige FTP-Schritt). Der Umgebungscheck zeigt vor der
     Installation beide Verzeichnisse an und warnt, wenn die Struktur
     nicht passt.
3. **setup.php im Browser aufrufen** (`https://deine-domain/setup.php`)
   - Umgebungscheck → „Installation starten": lädt das neueste Release von
     GitHub, prüft die SHA-256-Signatur, entpackt es und legt die
     Verzeichnisstruktur an (`current/`, `releases/`, `shared/`).
4. **/install ausfüllen**
   - Datenbank-Zugangsdaten (Verbindungstest inklusive) und die
     Bootstrap-Admin-Zugangsdaten festlegen.
   - „Frische Installation" wählen – oder „Backup einspielen", um eine
     bestehende Instanz umzuziehen.
5. **Erster Login** unter `/admin/login` mit den Bootstrap-Zugangsdaten –
   dabei wird das echte Admin-Konto angelegt; die Bootstrap-Daten sind
   danach ungültig.
6. **Cronjob im Kontrollpanel anlegen**: alle 10 Minuten die URL
   `https://deine-domain/cron/import?token=<cron_token>` aufrufen.
   Der Token steht in `shared/config.php`. Der Cron erledigt ICS-Import,
   Push-Versand, IP-Anonymisierung und Aufräumarbeiten.
7. **Im Admin einrichten**: Spielstätten (mit Begriffen für die
   Ortserkennung und Standard-Platz), Plätze, Teams, Import-Quellen
   (ICS-URLs von fussball.de), Einstellungen (Nutzungszeiten, Alarm-E-Mail),
   Impressum und Datenschutzerklärung.

## Updates

Admin → Update: Versionscheck gegen GitHub, dann läuft die Schrittkette
(Backup → Download mit Prüfsummen-Check → Entpacken → atomares Umschalten →
Migrationen → Selbsttest). Bei Fehlern: Schritt wiederholen oder Rollback auf
das vorherige Release.

**Release-Prozess**: Jedes Tag `vX.Y.Z` wird automatisch als **Pre-Release**
gebaut. Die Testinstanz (Update-Kanal „beta") spielt es zuerst ein; nach
erfolgreichem Test wird das Release auf GitHub als „latest" markiert, dann
zieht es die Produktivinstanz (Kanal „stable").

## Backup & Restore

- Admin → Backups: manuell erstellen und herunterladen; vor jedem Update
  entsteht automatisch eines. Die letzten 10 bleiben erhalten
  (`shared/var/backups/`).
- Wiederherstellen: auf einer frischen Instanz im Installer
  „Backup einspielen" wählen.

## Entwicklung

Voraussetzung: Docker. PHP/Composer werden lokal nicht benötigt.

```sh
# Abhängigkeiten installieren (einmalig bzw. nach composer.json-Änderungen)
docker run --rm -v ${PWD}:/app -w /app composer:2 install

# Umgebung starten → http://localhost:8080
docker compose up --build

# Tests (laufen im App-Container unter PHP 8.5; DB-Integrationstests
# nutzen die MariaDB aus docker compose und legen vereinskalender_test an)
docker compose run --rm app php vendor/bin/phpunit

# Migrationen anwenden
docker compose exec app php bin/migrate.php

# setup.php neu generieren (nach Änderungen an ReleaseDownloader/Template)
docker compose run --rm --no-deps app php bin/build_setup.php
```

Die Docker-Umgebung spiegelt das Layout eines typischen Shared-Hosting-Anbieters: `docker/web/` ist der
DocumentRoot mit dem Produktions-Shim, das Repo ist als `current/` gemountet,
`docker/shared/` entspricht dem persistenten `shared/`-Verzeichnis.

## Lizenz

GPLv3 – siehe [LICENSE](LICENSE). Der FullCalendar-Scheduler wird mit dem
Open-Source-Lizenzschlüssel (`GPL-My-Project-Is-Open-Source`) eingesetzt.
