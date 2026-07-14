<?php use App\Domain\Bereich; use App\Domain\Palette; ?>
<section class="narrow">
    <h2><?= e($title) ?></h2>
    <form method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Bereich
            <select name="bereich" required>
                <option value="">– wählen –</option>
                <?php foreach (Bereich::cases() as $bereich): ?>
                    <option value="<?= e($bereich->value) ?>" <?= ($values['bereich'] ?? '') === $bereich->value ? 'selected' : '' ?>>
                        <?= e($bereich->value === 'Herren' ? 'Herren' : $bereich->value . '-Jugend') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['bereich'])): ?><span class="field-error"><?= e($errors['bereich']) ?></span><?php endif; ?>
        </label>
        <label>
            Name (z. B. „E2")
            <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" required maxlength="100">
            <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
        </label>
        <label>
            Kürzel
            <input type="text" name="kuerzel" value="<?= e($values['kuerzel'] ?? '') ?>" required maxlength="10">
            <?php if (isset($errors['kuerzel'])): ?><span class="field-error"><?= e($errors['kuerzel']) ?></span><?php endif; ?>
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
            <input type="checkbox" name="aktiv" value="1" <?= ($values['aktiv'] ?? '') !== '' ? 'checked' : '' ?>>
            Aktiv (inaktive Teams verschwinden aus Filtern und Neuanlagen)
        </label>
        <label>
            Sortierung
            <input type="number" name="sortierung" value="<?= e($values['sortierung'] ?? 0) ?>">
        </label>
        <button type="submit">Speichern</button>
        <a href="/admin/teams">Abbrechen</a>
    </form>
</section>
