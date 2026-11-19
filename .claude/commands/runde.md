---
description: Änderungswünsche klären und als Runde (Sammel-Issue + Issues) für die Automatik anlegen
argument-hint: <Wünsche in Stichworten>
---
Mirko beschreibt Änderungswünsche für den Vereinskalender:

$ARGUMENTS

Mache daraus eine **Runde** für den Workflow `entwicklung`
(`docs/VORGEHEN.md`). Du legst nur Issues an – umgesetzt wird erst, wenn
Mirko die Runde startet.

1. **Verstehen.** Lies die betroffenen Abschnitte aus CLAUDE.md und den
   zugehörigen Code, damit die Issues zur bestehenden Architektur passen
   (Event Sourcing, Offline-Ports, CSP, Shared-Hosting-Constraints).
2. **Klären.** Fachlich Unklares fragst du Mirko (AskUserQuestion, wenige
   gebündelte Fragen mit Empfehlung als erste Option). Nichts Fachliches
   erfinden. Technisches entscheidest du selbst.
3. **Schneiden.** Je Issue eine Aufgabe, die ein Agent in einer Sitzung
   schafft. Lieber wenige, in sich geschlossene Issues als viele kleine,
   die dieselben Dateien anfassen (parallel laufende Agenten bekommen
   sonst Merge-Konflikte). Abhängigkeiten nur, wo ein Issue wirklich auf
   dem anderen aufbaut. Je Issue festlegen:
   - **Modell**: `opus` (Datenmodell, Event Sourcing, Import, Updater,
     Sicherheit, knifflige UI), `sonnet` (normale Features), `haiku`
     (kleine, klar umrissene Änderungen).
   - **Effort**: `low` | `medium` | `high` | `xhigh`.
4. **Vorlegen.** Zeige Mirko die Liste (Titel, Modell, Effort,
   Abhängigkeiten, je 1–2 Sätze Inhalt) und hole sein OK ein, bevor du
   etwas auf GitHub anlegst. Sag dazu, dass sein OK zugleich die Umsetzung
   startet (viele Agenten, danach Pre-Release) – will er nur die Issues,
   sagt er es.
5. **Anlegen** in `MirkoSc/vereinskalender` (GitHub-MCP,
   `mcp__github__issue_write`):
   - Je Aufgabe ein Issue, Labels `auto`, `modell:<m>`, `effort:<e>`.
     Body nach `.github/ISSUE_TEMPLATE/feature.md`, beginnend mit der Zeile
     `**Modell:** … · **Effort:** … · **Runde:** <Name>` und
     `Abhängig von: #a, #b` (oder `Abhängig von: –`), dann Ziel,
     CLAUDE.md-Abschnitte, Umfang, Abnahmekriterien, Definition of Done.
   - Danach das **Sammel-Issue** (Label `runde`): Titel „Runde: <Thema>",
     die getroffenen Entscheidungen als Liste, die Issues als Aufgabenliste
     (`- [ ] #nr Titel`) und am Ende der fertige Startbefehl:

     ```
     Führe den Workflow entwicklung aus mit
     { sammelIssue: <nr>, release: true }
     ```
6. **Starten.** Mirkos OK aus Schritt 4 gilt als Auftrag für die
   Umsetzung: Nenne ihm die Links und führe dann sofort den Workflow
   `entwicklung` aus (Workflow-Tool, Name `entwicklung`, args
   `{ sammelIssue: <nr>, release: true }`). Nur wenn er „nur anlegen" o. Ä.
   gesagt hat, nicht starten, sondern den Startbefehl nennen.
