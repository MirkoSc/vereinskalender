<section>
    <h2>Saison-Assistent</h2>
    <p>Geführter Ablauf zum Saisonwechsel in drei Schritten:</p>

    <h3>1. Teams anpassen</h3>
    <p>
        Teams umbenennen (z. B. „E1" → „D2"), nicht mehr gemeldete Teams deaktivieren,
        neue Teams <a href="/admin/teams/neu">anlegen</a>. Inaktive Teams verschwinden aus
        Filtern und Neuanlagen, ihre Historie bleibt erhalten.
    </p>
    <ul>
        <?php foreach ($teams as $team): ?>
            <li>
                <a href="/admin/teams/<?= e($team['id']) ?>"><?= e($team['name']) ?> (<?= e($team['bereich']) ?>)</a>
                <?= (int) $team['aktiv'] === 1 ? '' : '– inaktiv' ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <h3>2. Import-URLs erneuern</h3>
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

    <h3>3. Trainingsslots übernehmen</h3>
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
                        <td><?= e($slot['team_name'] ?? ('Team #' . $slot['team_id'])) ?></td>
                        <td><?= e($slot['pitch_name'] ?? ('Platz #' . $slot['pitch_id'])) ?></td>
                        <td><?= e($wochentage[(int) $slot['wochentag']] ?? $slot['wochentag']) ?> <?= e(substr((string) $slot['beginn'], 0, 5)) ?>–<?= e(substr((string) $slot['ende'], 0, 5)) ?></td>
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
</section>
