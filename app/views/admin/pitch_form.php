<section class="narrow">
    <h2><?= e($title) ?></h2>
    <?php if ($venues === []): ?>
        <p class="error-message">
            Es gibt noch keine Spielstätte. <a href="/admin/spielstaetten/neu">Zuerst eine Spielstätte anlegen.</a>
        </p>
    <?php endif; ?>
    <form method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Spielstätte
            <select name="venue_id" required>
                <option value="">– wählen –</option>
                <?php foreach ($venues as $venue): ?>
                    <option value="<?= e($venue['id']) ?>" <?= (string) ($values['venue_id'] ?? '') === (string) $venue['id'] ? 'selected' : '' ?>>
                        <?= e($venue['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['venue_id'])): ?><span class="field-error"><?= e($errors['venue_id']) ?></span><?php endif; ?>
        </label>
        <label>
            Name (z. B. „Rasenplatz 1")
            <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" required maxlength="100">
            <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
        </label>
        <label>
            Typ (z. B. „Rasen", „Kunstrasen")
            <input type="text" name="typ" value="<?= e($values['typ'] ?? '') ?>" maxlength="50">
            <?php if (isset($errors['typ'])): ?><span class="field-error"><?= e($errors['typ']) ?></span><?php endif; ?>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="flutlicht" value="1" <?= ($values['flutlicht'] ?? '') !== '' ? 'checked' : '' ?>>
            Flutlicht vorhanden
        </label>
        <label>
            Abweichende Adresse (leer = Adresse der Spielstätte)
            <input type="text" name="adresse" value="<?= e($values['adresse'] ?? '') ?>" maxlength="255">
            <?php if (isset($errors['adresse'])): ?><span class="field-error"><?= e($errors['adresse']) ?></span><?php endif; ?>
        </label>
        <label>
            Sortierung
            <input type="number" name="sortierung" value="<?= e($values['sortierung'] ?? 0) ?>">
        </label>
        <button type="submit">Speichern</button>
        <a href="/admin/plaetze">Abbrechen</a>
    </form>
</section>
