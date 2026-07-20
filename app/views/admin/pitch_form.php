<?php use App\Domain\Palette; ?>
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
            Kürzel (z. B. „R1", erscheint als Text bei der Platz-Gruppierung im Spielplan)
            <input type="text" name="kuerzel" value="<?= e($values['kuerzel'] ?? '') ?>" required maxlength="10">
            <?php if (isset($errors['kuerzel'])): ?><span class="field-error"><?= e($errors['kuerzel']) ?></span><?php endif; ?>
        </label>
        <label>
            Typ (z. B. „Rasen", „Kunstrasen")
            <input type="text" name="typ" value="<?= e($values['typ'] ?? '') ?>" maxlength="50">
            <?php if (isset($errors['typ'])): ?><span class="field-error"><?= e($errors['typ']) ?></span><?php endif; ?>
        </label>
        <fieldset>
            <legend>Farbe</legend>
            <div class="palette">
                <?php foreach (Palette::COLORS as $hex => $label): ?>
                    <label class="palette-option" title="<?= e($label) ?>">
                        <input type="radio" name="farbe" value="<?= e($hex) ?>" <?= ($values['farbe'] ?? '') === $hex ? 'checked' : '' ?>>
                        <span class="swatch" style="background: <?= e($hex) ?>"></span>
                        <span class="palette-label"><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if (isset($errors['farbe'])): ?><span class="field-error"><?= e($errors['farbe']) ?></span><?php endif; ?>
        </fieldset>
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
            Sportheim (nur falls der Platz an einem Sportheim liegt)
            <select name="sportheim_id">
                <option value="">– keines –</option>
                <?php foreach ($sportheime as $sportheim): ?>
                    <option value="<?= e($sportheim['id']) ?>" <?= (string) ($values['sportheim_id'] ?? '') === (string) $sportheim['id'] ? 'selected' : '' ?>>
                        <?= e($sportheim['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['sportheim_id'])): ?><span class="field-error"><?= e($errors['sportheim_id']) ?></span><?php endif; ?>
        </label>
        <label>
            Sortierung
            <input type="number" name="sortierung" value="<?= e($values['sortierung'] ?? 0) ?>">
        </label>
        <button type="submit">Speichern</button>
        <a href="/admin/plaetze">Abbrechen</a>
    </form>
</section>
