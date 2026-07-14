<section>
    <h2>Backups</h2>
    <p>
        Backups enthalten den kompletten DB-Dump, die config.php und ein Manifest.
        Vor jedem Update wird automatisch eines erstellt; die letzten <?= e(\App\Service\Backup\BackupService::KEEP) ?> bleiben erhalten.
        Wiederherstellen: auf einer frischen Instanz im Installer „Backup einspielen" wählen.
    </p>
    <form method="post" action="/admin/backups" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="button">Backup jetzt erstellen</button>
    </form>

    <?php if ($backups === []): ?>
        <p>Noch keine Backups vorhanden.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Datei</th><th>Größe</th><th>Erstellt</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><?= e($backup['name']) ?></td>
                    <td><?= e(number_format($backup['groesse'] / 1024, 0, ',', '.')) ?> KB</td>
                    <td><?= e($backup['geaendert_am']) ?></td>
                    <td><a href="/admin/backups/<?= e($backup['name']) ?>">Herunterladen</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
