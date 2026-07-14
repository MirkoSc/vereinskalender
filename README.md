# Vereinskalender

Webkalender für einen Fußballverein: Sportplatz-Belegung (Training) und
Spielplan. Läuft auf Shared Hosting (PHP 8.5 + MySQL/MariaDB).
Architektur und Konventionen: siehe [CLAUDE.md](CLAUDE.md).

## Entwicklung

Voraussetzung: Docker. PHP/Composer werden lokal nicht benötigt.

```sh
# Abhängigkeiten installieren (einmalig bzw. nach composer.json-Änderungen)
docker run --rm -v ${PWD}:/app -w /app composer:2 install

# Umgebung starten → http://localhost:8080
docker compose up --build

# Tests (laufen im App-Container unter PHP 8.5)
docker compose run --rm app php vendor/bin/phpunit

# Migrationen anwenden
docker compose exec app php bin/migrate.php
```

Die Docker-Umgebung spiegelt das Shared-Hosting-Layout: `docker/web/` ist der
DocumentRoot mit dem Produktions-Shim, das Repo ist als `current/` gemountet,
`docker/shared/` entspricht dem persistenten `shared/`-Verzeichnis.

## Release

Tag `vX.Y.Z` pushen → GitHub Action baut das Release-ZIP (inkl. `vendor/`),
erzeugt `checksums.txt` und veröffentlicht beides als **Pre-Release**.
Nach erfolgreichem Test auf der Beta-Instanz wird das Release manuell auf
„latest" umgestellt (Details: CLAUDE.md Abschnitt 11).

## Lizenz

GPLv3 – siehe [LICENSE](LICENSE).
