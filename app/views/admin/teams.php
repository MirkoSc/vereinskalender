<section>
    <h2>Teams</h2>
    <p><a class="button" href="/admin/teams/neu">Team anlegen</a></p>
    <?php if ($teams === []): ?>
        <p>Noch keine Teams angelegt.</p>
    <?php else: ?>
        <table data-sortable data-reorder-url="/admin/teams/sortierung">
            <thead>
                <tr><th></th><th>Farbe</th><th>Bereich</th><th>Name</th><th>Kürzel</th><th>Aktiv</th><th>Sortierung</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($teams as $team): ?>
                <tr data-id="<?= e($team['id']) ?>">
                    <td><span class="drag-handle" aria-hidden="true">⠿</span></td>
                    <td><span class="swatch" style="background: <?= e($team['farbe']) ?>"></span></td>
                    <td><?= e($bereiche[(int) ($team['bereich_id'] ?? 0)]['name'] ?? $team['bereich']) ?></td>
                    <td><a href="/admin/teams/<?= e($team['id']) ?>"><?= e($team['name']) ?></a></td>
                    <td><?= e($team['kuerzel']) ?></td>
                    <td><?= ((int) $team['aktiv'] === 1) ? 'ja' : 'nein' ?></td>
                    <td><?= e($team['sortierung']) ?></td>
                    <td>
                        <form method="post" action="/admin/teams/<?= e($team['id']) ?>/loeschen" class="inline-form"
                              onsubmit="return confirm('Team „<?= e($team['name']) ?>&#8220; wirklich löschen?');">
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
