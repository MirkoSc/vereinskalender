<?php use App\Domain\Palette; ?>
<section class="narrow">
    <h2><?= e($title) ?></h2>
    <form method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Name (z. B. „SV Musterstadt")
            <input type="text" name="name" value="<?= e($values['name'] ?? '') ?>" required maxlength="100">
            <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
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
        <label>
            Adresse
            <input type="text" name="adresse" value="<?= e($values['adresse'] ?? '') ?>" required maxlength="255">
            <?php if (isset($errors['adresse'])): ?><span class="field-error"><?= e($errors['adresse']) ?></span><?php endif; ?>
        </label>
        <?php if ($venuePitches !== []): ?>
            <label>
                Standard-Platz für Heimspiele
                <select name="default_pitch_id">
                    <option value="">– keiner –</option>
                    <?php foreach ($venuePitches as $pitch): ?>
                        <option value="<?= e($pitch['id']) ?>" <?= (string) ($values['default_pitch_id'] ?? '') === (string) $pitch['id'] ? 'selected' : '' ?>>
                            <?= e($pitch['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['default_pitch_id'])): ?><span class="field-error"><?= e($errors['default_pitch_id']) ?></span><?php endif; ?>
            </label>
        <?php endif; ?>
        <label>
            Sortierung
            <input type="number" name="sortierung" value="<?= e($values['sortierung'] ?? 0) ?>">
        </label>
        <button type="submit">Speichern</button>
        <a href="/admin/spielstaetten">Abbrechen</a>
    </form>

    <?php if (($begriffe ?? null) !== null): ?>
        <h3>Begriffe für die Ortserkennung</h3>
        <p>
            Der erste Begriff (nach Sortierung), der im Ort eines importierten Spiels vorkommt,
            ordnet das Spiel dieser Spielstätte zu.
        </p>
        <?php if ($begriffe === []): ?>
            <p>Noch keine Begriffe hinterlegt.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Begriff</th><th>Sortierung</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($begriffe as $begriff): ?>
                    <tr>
                        <td><?= e($begriff['begriff']) ?></td>
                        <td><?= e($begriff['sortierung']) ?></td>
                        <td>
                            <form method="post" action="/admin/begriffe/<?= e($begriff['id']) ?>/loeschen" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="linklike danger">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" action="/admin/spielstaetten/<?= e($venueId) ?>/begriffe" class="begriff-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>
                Neuer Begriff
                <input type="text" name="begriff" required maxlength="100">
            </label>
            <label>
                Sortierung
                <input type="number" name="sortierung" value="0">
            </label>
            <button type="submit">Hinzufügen</button>
        </form>
    <?php endif; ?>
</section>
