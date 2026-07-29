<section>
    <h2>Spielstätten</h2>
    <p><a class="button" href="/admin/spielstaetten/neu">Spielstätte anlegen</a></p>
    <?php if ($venues === []): ?>
        <p>Noch keine Spielstätten angelegt.</p>
    <?php else: ?>
        <table data-sortable data-reorder-url="/admin/spielstaetten/sortierung">
            <thead>
                <tr><th></th><th>Farbe</th><th>Name</th><th>Adresse</th><th>Plätze</th><th>Begriffe</th><th>Sortierung</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($venues as $venue): ?>
                <tr data-id="<?= e($venue['id']) ?>">
                    <td><span class="drag-handle" aria-hidden="true">⠿</span></td>
                    <td><span class="swatch" style="background: <?= e($venue['farbe']) ?>"></span></td>
                    <td><a href="/admin/spielstaetten/<?= e($venue['id']) ?>"><?= e($venue['name']) ?></a></td>
                    <td><?= e($venue['adresse']) ?></td>
                    <td><?= e($venue['pitch_count']) ?></td>
                    <td><?= e($venue['begriff_count']) ?></td>
                    <td><?= e($venue['sortierung']) ?></td>
                    <td>
                        <form method="post" action="/admin/spielstaetten/<?= e($venue['id']) ?>/loeschen" class="inline-form"
                              data-confirm="Spielstätte „<?= e($venue['name']) ?>&#8220; wirklich löschen?">
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
