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
                        <form method="post" action="/admin/import-quellen/<?= e($source['id']) ?>/loeschen" class="inline-form"
                              onsubmit="return confirm('Import-Quelle wirklich löschen?');">
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
</section>
