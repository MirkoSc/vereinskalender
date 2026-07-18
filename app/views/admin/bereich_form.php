<section class="narrow">
    <h2><?= e($title) ?></h2>
    <form method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Name (z. B. „E-Jugend")
            <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" required maxlength="100">
            <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
        </label>
        <label>
            Kürzel
            <input type="text" name="kuerzel" value="<?= e($values['kuerzel'] ?? '') ?>" required maxlength="10">
            <?php if (isset($errors['kuerzel'])): ?><span class="field-error"><?= e($errors['kuerzel']) ?></span><?php endif; ?>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="aktiv" value="1" <?= ($values['aktiv'] ?? '') !== '' ? 'checked' : '' ?>>
            Aktiv (inaktive Bereiche verschwinden aus Filtern und der Team-Neuanlage)
        </label>
        <label>
            Sortierung
            <input type="number" name="sortierung" value="<?= e($values['sortierung'] ?? 0) ?>">
        </label>
        <button type="submit">Speichern</button>
        <a href="/admin/bereiche">Abbrechen</a>
    </form>
</section>
