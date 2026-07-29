<section>
    <h2>Bereiche</h2>
    <p><a class="button" href="/admin/bereiche/neu">Bereich anlegen</a></p>
    <?php if ($bereiche === []): ?>
        <p>Noch keine Bereiche angelegt.</p>
    <?php else: ?>
        <table data-sortable data-reorder-url="/admin/bereiche/sortierung">
            <thead>
                <tr><th></th><th>Name</th><th>Kürzel</th><th>Aktiv</th><th>Sortierung</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($bereiche as $bereich): ?>
                <tr data-id="<?= e($bereich['id']) ?>">
                    <td><span class="drag-handle" aria-hidden="true">⠿</span></td>
                    <td><a href="/admin/bereiche/<?= e($bereich['id']) ?>"><?= e($bereich['name']) ?></a></td>
                    <td><?= e($bereich['kuerzel']) ?></td>
                    <td><?= ((int) $bereich['aktiv'] === 1) ? 'ja' : 'nein' ?></td>
                    <td><?= e($bereich['sortierung']) ?></td>
                    <td>
                        <form method="post" action="/admin/bereiche/<?= e($bereich['id']) ?>/loeschen" class="inline-form"
                              data-confirm="Bereich „<?= e($bereich['name']) ?>&#8220; wirklich löschen?">
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
