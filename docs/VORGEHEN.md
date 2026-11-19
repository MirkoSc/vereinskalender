# Vorgehen: automatische Entwicklung

Übernommen aus der Mitgliederverwaltung. Neue Aufgaben laufen in **Runden**:
Wünsche klären → Issues anlegen → Agenten setzen um, Opus prüft, es wird
gemergt → Pre-Release. Ein Mensch muss nur die Wünsche nennen, die Runde
starten und bei `braucht-hilfe` nachsehen.

## 1. Runde anlegen

In einer Claude-Code-Sitzung mit diesem Repository:

```
/runde <Wünsche in Stichworten>
```

(`.claude/commands/runde.md`). Claude liest den betroffenen Code, stellt
fachliche Rückfragen, schlägt einen Schnitt in Issues vor und legt nach
deinem OK an:

- je Aufgabe ein Issue mit Labels `auto`, `modell:opus|sonnet|haiku`,
  `effort:low|medium|high|xhigh` und der Zeile `Abhängig von: #…`,
- ein **Sammel-Issue** (Label `runde`) mit Entscheidungen, Aufgabenliste
  und dem fertigen Startbefehl.

Issues von Hand anlegen geht genauso (Vorlage „Feature / Aufgabe"): wer das
Label `auto` setzt, gibt das Issue für die Automatik frei.

## 2. Runde starten

In der Sitzung sagen:

```
Führe den Workflow entwicklung aus mit { sammelIssue: 123, release: true }
```

Der Workflow (`.claude/workflows/entwicklung.js`)

- liest die offenen `auto`-Issues des Sammel-Issues (ohne `sammelIssue`:
  alle offenen `auto`-Issues), Modell und Effort aus den Labels,
  Abhängigkeiten aus dem Body; geschlossene werden übersprungen
  (Fortsetzen ist jederzeit möglich),
- startet jedes Issue, sobald seine Abhängigkeiten gemergt sind – je Issue
  ein Agent im eigenen Worktree (`/home/user/wt/issue-<nr>`, Branch
  `issue-<nr>`), höchstens 4 gleichzeitig (jeder startet eine eigene
  Docker-Umgebung),
- lässt jeden PR von Opus 5.5 prüfen (bis zu 3 Reparatur-Runden),
- mergt `main` ein, vergibt bei Bedarf die Migrationsnummer neu, testet,
  squash-mergt und schließt das Issue,
- mit `release`: wartet auf grüne CI auf `main`, startet `release.yml`
  (`workflow_dispatch`, immer Pre-Release) und schreibt den Bericht ins
  Sammel-Issue.

Argumente (alle optional):

| Argument | Bedeutung |
|---|---|
| `sammelIssue: 123` | nur die dort verlinkten Issues; Bericht und `braucht-hilfe` dorthin |
| `release: true` | danach Pre-Release mit der nächsten Minor-Version (7.8.0 → 7.9.0) |
| `release: { version: '8.0.0' }` | danach Pre-Release mit genau dieser Version |
| `plan: [{ n: 120, m: 'opus', e: 'high', d: [] }, …]` | Plan von Hand statt aus den Labels |
| `maxParallel: 2` | weniger Agenten gleichzeitig |

Die Sitzung muss laufen bleiben. Startet der Container neu, den Workflow
einfach erneut starten – er setzt an den offenen Issues und PRs fort.

## 3. Wenn etwas hängt

Issues mit **`braucht-hilfe`**: der Kommentar am Issue nennt den Grund.
Hängt ein Issue, werden davon abhängige übersprungen und es gibt **kein
Release**. Nach der Klärung (Issue präzisieren, von Hand fixen) das Label
entfernen und den Workflow erneut starten.

Von Hand arbeiten geht weiterhin: `/issue <nr>` (`.claude/commands/issue.md`).

## 4. Release freigeben

Der Workflow erzeugt immer ein **Pre-Release**. Dann:

1. Testinstanz (Update-Kanal „beta") → Admin → Update → testen.
2. Auf GitHub das Release als „latest" markieren – erst dann zieht die
   Produktivinstanz (Kanal „stable") nach.

Ein Release ohne Workflow: Actions → „Release" → „Run workflow" auf `main`
mit Version, oder wie bisher ein Tag pushen
(`git tag v7.9.0 && git push origin v7.9.0`).

## Regeln für parallele Agenten

- Je Agent ein eigenes Docker-Projekt: `docker compose -p issue-<nr> run
  --rm app vendor/bin/phpunit`. `run` veröffentlicht keine Ports und die
  Datenbank gar keine – nur ein bewusstes `docker compose up` belegt 8080.
- Das Image (`docker/php/Dockerfile`) baut ohne Debian-Paketspiegel (in der
  Agenten-Sandbox gesperrt); Images kommen von `mirror.gcr.io`.
- **Migrationsnummern** werden erst beim Merge endgültig: der Migrator
  wendet nur Nummern > `MAX(version)` an. Der Merge-Schritt benennt eine
  zu kleine oder doppelte Nummer auf die nächste freie um.
- CLAUDE.md ändern viele PRs – Konflikte dort löst der Merge-Schritt so,
  dass beide Seiten erhalten bleiben.
- Issues so schneiden, dass parallel laufende möglichst verschiedene
  Dateien anfassen; sonst lieber eine Abhängigkeit eintragen.
