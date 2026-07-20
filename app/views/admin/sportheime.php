<section>
    <h2>Sportheime</h2>
    <p><a class="button" href="/admin/sportheime/neu">Sportheim anlegen</a></p>
    <?php if ($sportheime === []): ?>
        <p>Noch keine Sportheime angelegt.</p>
    <?php else: ?>
        <table data-sortable data-reorder-url="/admin/sportheime/sortierung">
            <thead>
                <tr><th></th><th>Name</th><th>Spielstätte</th><th>Aktiv</th><th>Sortierung</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($sportheime as $sportheim): ?>
                <tr data-id="<?= e($sportheim['id']) ?>">
                    <td><span class="drag-handle" aria-hidden="true">⠿</span></td>
                    <td><a href="/admin/sportheime/<?= e($sportheim['id']) ?>"><?= e($sportheim['name']) ?></a></td>
                    <td><?= e($sportheim['venue_name'] ?? '') ?></td>
                    <td><?= ((int) $sportheim['aktiv'] === 1) ? 'ja' : 'nein' ?></td>
                    <td><?= e($sportheim['sortierung']) ?></td>
                    <td>
                        <form method="post" action="/admin/sportheime/<?= e($sportheim['id']) ?>/loeschen" class="inline-form"
                              onsubmit="return confirm('Sportheim „<?= e($sportheim['name']) ?>&#8220; wirklich löschen?');">
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
