<section>
    <h2>Saison-Assistent</h2>
    <p>Geführter Ablauf zum Saisonwechsel in fünf Schritten:</p>

    <h3>1. Bereiche prüfen (Aufstieg)</h3>
    <p>
        Beim Aufstieg wechseln Teams den Bereich (z. B. G→F, F→E). Neue Bereiche wie
        A-/B-Jugend zuerst in der <a href="/admin/bereiche">Bereichs-Verwaltung</a> anlegen,
        falls sie fehlen; danach je Team im <a href="/admin/teams">Team-Formular</a> den
        neuen Bereich zuweisen (Schritt 2).
    </p>

    <h3>2. Teams anpassen</h3>
    <p>
        Teams umbenennen (z. B. „E1" → „D2"), nicht mehr gemeldete Teams deaktivieren,
        neue Teams <a href="/admin/teams/neu">anlegen</a>. Inaktive Teams verschwinden aus
        Filtern und Neuanlagen, ihre Historie bleibt erhalten.
    </p>
    <ul>
        <?php foreach ($teams as $team): ?>
            <li>
                <a href="/admin/teams/<?= e($team['id']) ?>"><?= e($team['name']) ?> (<?= e($bereiche[(int) ($team['bereich_id'] ?? 0)]['name'] ?? $team['bereich']) ?>)</a>
                <?= (int) $team['aktiv'] === 1 ? '' : '– inaktiv' ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <h3>3. Import-URLs erneuern</h3>
    <p>
        fussball.de vergibt pro Saison neue ICS-URLs. Alle Quellen prüfen und die URLs
        <a href="/admin/import-quellen">aktualisieren</a>:
    </p>
    <ul>
        <?php foreach ($sources as $source): ?>
            <li>
                <a href="/admin/import-quellen/<?= e($source['id']) ?>"><?= e($source['team_name'] ?? ('Quelle #' . $source['id'])) ?></a>
                – Status: <?= e($source['letzter_status'] ?? 'noch nie gelaufen') ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <h3>4. Trainingsslots übernehmen</h3>
    <?php if ($slots === []): ?>
        <p>Keine Trainingsslots vorhanden.</p>
    <?php else: ?>
        <p>
            Slots der Vorsaison als Kopiervorlage in den neuen Gültigkeitszeitraum übernehmen.
            Jede Kopie durchläuft die normale Konfliktprüfung.
        </p>
        <form method="post" action="/admin/saison/slots-kopieren">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <table>
                <thead><tr><th></th><th>Team</th><th>Platz</th><th>Zeit</th><th>Gültig</th></tr></thead>
                <tbody>
                <?php $wochentage = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So']; ?>
                <?php foreach ($slots as $slot): ?>
                    <tr>
                        <td><input type="checkbox" name="slot_ids[]" value="<?= e($slot['id']) ?>" <?= (int) $slot['abgelaufen'] === 1 ? 'checked' : '' ?>></td>
                        <td><?= e($slot['team_names']) ?></td>
                        <td><?= e($slot['pitch_name'] ?? ('Platz #' . $slot['pitch_id'])) ?></td>
                        <td><?= e(implode('+', array_map(static fn(int $w): string => $wochentage[$w] ?? (string) $w, $slot['wochentage_list']))) ?> <?= e(substr((string) $slot['beginn'], 0, 5)) ?>–<?= e(substr((string) $slot['ende'], 0, 5)) ?><?= (int) $slot['intervall_wochen'] > 1 ? ' · alle ' . e((string) (int) $slot['intervall_wochen']) . ' Wochen' : '' ?></td>
                        <td><?= e($slot['gueltig_ab']) ?> bis <?= e($slot['gueltig_bis']) ?><?= (int) $slot['abgelaufen'] === 1 ? ' (abgelaufen)' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="field-row">
                <label>Neue Saison: gültig ab <input type="date" name="gueltig_ab" required></label>
                <label>gültig bis <input type="date" name="gueltig_bis" required></label>
            </div>
            <button type="submit" class="button">Ausgewählte Slots übernehmen</button>
        </form>
    <?php endif; ?>

    <h3>5. Heimspielstätten-Regeln übernehmen</h3>
    <?php if ($homePitchRules === []): ?>
        <p>Keine Heimspielstätten-Regeln vorhanden.</p>
    <?php else: ?>
        <p>
            Regeln der Vorsaison als Kopiervorlage in einen neuen Gültigkeitszeitraum übernehmen.
            Jede Kopie durchläuft die Überlappungs-Prüfung je Team.
        </p>
        <form method="post" action="/admin/saison/heimplaetze-kopieren">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <table>
                <thead><tr><th></th><th>Team</th><th>Platz</th><th>Bisher gültig</th><th>Neu gültig ab</th><th>Neu gültig bis</th></tr></thead>
                <tbody>
                <?php foreach ($homePitchRules as $rule): ?>
                    <?php
                        $neuAb = date('Y-m-d', strtotime((string) $rule['gueltig_ab'] . ' +1 year'));
                        $neuBis = date('Y-m-d', strtotime((string) $rule['gueltig_bis'] . ' +1 year'));
                    ?>
                    <tr>
                        <td><input type="checkbox" name="rule_ids[]" value="<?= e($rule['id']) ?>" <?= (int) $rule['abgelaufen'] === 1 ? 'checked' : '' ?>></td>
                        <td><?= e($rule['team_name'] ?? ('Team #' . $rule['team_id'])) ?></td>
                        <td><?= e($rule['pitch_name'] ?? ('Platz #' . $rule['pitch_id'])) ?></td>
                        <td><?= e($rule['gueltig_ab']) ?> bis <?= e($rule['gueltig_bis']) ?><?= (int) $rule['abgelaufen'] === 1 ? ' (abgelaufen)' : '' ?></td>
                        <td><input type="date" name="gueltig_ab[<?= e($rule['id']) ?>]" value="<?= e($neuAb) ?>" required></td>
                        <td><input type="date" name="gueltig_bis[<?= e($rule['id']) ?>]" value="<?= e($neuBis) ?>" required></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="button">Ausgewählte Regeln übernehmen</button>
        </form>
    <?php endif; ?>
</section>
