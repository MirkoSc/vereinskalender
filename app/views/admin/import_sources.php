<section>
    <h2>Import-Quellen</h2>
    <p>
        <a class="button" href="/admin/import-quellen/neu">Import-Quelle anlegen</a>
        <form method="post" action="/admin/import/run" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit">Import jetzt ausführen</button>
        </form>
    </p>
    <p>
        Der Import läuft regulär alle 10 Minuten über den Cron-Endpoint
        <code>/cron/import?token=…</code> (Token aus <code>shared/config.php</code>).
    </p>
    <?php if ($sources === []): ?>
        <p>Noch keine Import-Quellen angelegt.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Team</th><th>ICS-URL</th><th>Aktiv</th><th>Letzter Lauf</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($sources as $source): ?>
                <tr>
                    <td><a href="/admin/import-quellen/<?= e($source['id']) ?>"><?= e($source['team_name'] ?? ('Team #' . $source['team_id'])) ?></a></td>
                    <td class="url-cell" title="<?= e($source['ics_url']) ?>"><?= e(mb_strimwidth((string) $source['ics_url'], 0, 60, '…')) ?></td>
                    <td><?= ((int) $source['aktiv'] === 1) ? 'ja' : 'nein' ?></td>
                    <td><?= e($source['letzter_lauf'] ?? '–') ?></td>
                    <td>
                        <?php if ($source['letzter_status'] === 'fehler'): ?>
                            <span class="error-message" title="<?= e($source['fehlertext'] ?? '') ?>">Fehler</span>
                        <?php elseif ($source['letzter_status'] === 'ok'): ?>
                            OK
                        <?php else: ?>
                            –
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="/admin/import-quellen/<?= e($source['id']) ?>/reset" class="inline-form"
                              data-confirm="Alle importierten Spiele dieser Quelle ab jetzt löschen und den Feed neu abrufen? Vergangene und manuell angelegte Spiele bleiben erhalten.">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="linklike">Spiele zurücksetzen</button>
                        </form>
                        <form method="post" action="/admin/import-quellen/<?= e($source['id']) ?>/loeschen" class="inline-form"
                              data-confirm="Import-Quelle wirklich löschen?">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="linklike danger">Löschen</button>
                        </form>
                    </td>
                </tr>
                <?php if ($source['letzter_status'] === 'fehler' && ($source['fehlertext'] ?? '') !== ''): ?>
                    <tr><td colspan="6" class="error-message"><?= e($source['fehlertext']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ($verwaiste !== []): ?>
        <h3>Verwaiste Import-Spiele</h3>
        <p>
            Diese Spiele stammen aus einer Import-Quelle, die es nicht mehr gibt (z. B. gelöscht
            und neu angelegt). Kein Import fasst sie mehr an – sie werden nie aktualisiert,
            abgesagt oder ersetzt und können zu Doppel-Terminen führen. Manuell angelegte Spiele
            sind hiervon nie betroffen.
        </p>
        <table>
            <thead>
                <tr><th>Team</th><th>Anstoß</th><th>Gegner</th><th>Tote Quellen-ID</th></tr>
            </thead>
            <tbody>
            <?php foreach ($verwaiste as $match): ?>
                <tr>
                    <td><?= e($match['team_name']) ?></td>
                    <td><?= e($match['anstoss']) ?></td>
                    <td><?= e($match['gegner']) ?></td>
                    <td><?= e($match['import_source_id']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <form method="post" action="/admin/import-spiele/verwaist/loeschen" class="inline-form"
                  data-confirm="<?= e(count($verwaiste)) ?> verwaiste Import-Spiele endgültig löschen? Auch vergangene Spiele sind dabei.">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="danger"><?= e(count($verwaiste)) ?> verwaiste Import-Spiele löschen</button>
            </form>
        </p>
    <?php endif; ?>
</section>
