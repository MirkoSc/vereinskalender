<section class="narrow">
    <h2><?= e($title) ?></h2>
    <form method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Heimverein
            <select name="venue_id" required>
                <option value="">– bitte wählen –</option>
                <?php foreach ($venues as $venue): ?>
                    <option value="<?= e($venue['id']) ?>" <?= (string) ($values['venue_id'] ?? '') === (string) $venue['id'] ? 'selected' : '' ?>>
                        <?= e($venue['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['venue_id'])): ?><span class="field-error"><?= e($errors['venue_id']) ?></span><?php endif; ?>
        </label>
        <label>
            Name (z. B. „Sportheim Musterstadt")
            <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" required maxlength="100">
            <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
        </label>
        <label>
            Adresse (nur falls abweichend von der Spielstätte)
            <input type="text" name="adresse" value="<?= e($values['adresse'] ?? '') ?>" maxlength="255">
            <?php if (isset($errors['adresse'])): ?><span class="field-error"><?= e($errors['adresse']) ?></span><?php endif; ?>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="aktiv" value="1" <?= ($values['aktiv'] ?? '') !== '' ? 'checked' : '' ?>>
            Aktiv (inaktive Sportheime verschwinden aus Filtern und der Vermietungs-Neuanlage)
        </label>
        <label>
            Sortierung
            <input type="number" name="sortierung" value="<?= e($values['sortierung'] ?? 0) ?>">
        </label>
        <button type="submit">Speichern</button>
        <a href="/admin/sportheime">Abbrechen</a>
    </form>

    <?php if (($raeume ?? null) !== null): ?>
        <h3>Räume</h3>
        <p>Eine Vermietung kann das ganze Sportheim oder einzelne Räume betreffen.</p>
        <?php if ($raeume === []): ?>
            <p>Noch keine Räume angelegt.</p>
        <?php else: ?>
            <table data-sortable data-reorder-url="/admin/sportheime/raeume/sortierung">
                <thead><tr><th></th><th>Name</th><th>Kürzel</th><th>Aktiv</th><th>Sortierung</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($raeume as $raum): ?>
                    <tr data-id="<?= e($raum['id']) ?>">
                        <td><span class="drag-handle" aria-hidden="true">⠿</span></td>
                        <td><?= e($raum['name']) ?></td>
                        <td><?= e($raum['kuerzel']) ?></td>
                        <td><?= ((int) $raum['aktiv'] === 1) ? 'ja' : 'nein' ?></td>
                        <td><?= e($raum['sortierung']) ?></td>
                        <td>
                            <form method="post" action="/admin/raeume/<?= e($raum['id']) ?>/loeschen" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="linklike danger">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" action="/admin/sportheime/<?= e($sportheimId) ?>/raeume" class="begriff-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>
                Neuer Raum (z. B. „Gastraum", „Kegelbahn")
                <input type="text" name="name" required maxlength="100">
            </label>
            <label>
                Kürzel
                <input type="text" name="kuerzel" required maxlength="10">
            </label>
            <label class="checkbox">
                <input type="checkbox" name="aktiv" value="1" checked>
                Aktiv
            </label>
            <button type="submit">Hinzufügen</button>
        </form>
    <?php endif; ?>
</section>
