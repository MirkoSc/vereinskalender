<section>
    <h2>Plätze</h2>
    <p><a class="button" href="/admin/plaetze/neu">Platz anlegen</a></p>
    <?php if ($pitches === []): ?>
        <p>Noch keine Plätze angelegt. Lege zuerst eine <a href="/admin/spielstaetten">Spielstätte</a> an.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr><th>Farbe</th><th>Name</th><th>Kürzel</th><th>Spielstätte</th><th>Typ</th><th>Flutlicht</th><th>Sortierung</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($pitches as $pitch): ?>
                <tr>
                    <td><span class="swatch" style="background: <?= e($pitch['farbe']) ?>"></span></td>
                    <td><a href="/admin/plaetze/<?= e($pitch['id']) ?>"><?= e($pitch['name']) ?></a></td>
                    <td><?= e($pitch['kuerzel']) ?></td>
                    <td><?= e($pitch['venue_name'] ?? '–') ?></td>
                    <td><?= e($pitch['typ']) ?></td>
                    <td><?= ((int) $pitch['flutlicht'] === 1) ? 'ja' : 'nein' ?></td>
                    <td><?= e($pitch['sortierung']) ?></td>
                    <td>
                        <form method="post" action="/admin/plaetze/<?= e($pitch['id']) ?>/loeschen" class="inline-form"
                              onsubmit="return confirm('Platz „<?= e($pitch['name']) ?>&#8220; wirklich löschen?');">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <button type="submit" class="linklike danger">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
