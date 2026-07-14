<section class="narrow">
    <h2><?= e($title) ?></h2>
    <form method="post" action="<?= e($action) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Team
            <select name="team_id" required>
                <option value="">– wählen –</option>
                <?php foreach ($teams as $team): ?>
                    <option value="<?= e($team['id']) ?>" <?= (string) ($values['team_id'] ?? '') === (string) $team['id'] ? 'selected' : '' ?>>
                        <?= e($team['name']) ?> (<?= e($team['bereich']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['team_id'])): ?><span class="field-error"><?= e($errors['team_id']) ?></span><?php endif; ?>
        </label>
        <label>
            ICS-URL (z. B. der Kalender-Export von fussball.de; fussball.de vergibt pro Saison neue URLs)
            <input type="url" name="ics_url" value="<?= e($values['ics_url'] ?? '') ?>" required maxlength="500"
                   placeholder="https://www.fussball.de/export.ics/…">
            <?php if (isset($errors['ics_url'])): ?><span class="field-error"><?= e($errors['ics_url']) ?></span><?php endif; ?>
        </label>
        <label class="checkbox">
            <input type="checkbox" name="aktiv" value="1" <?= ($values['aktiv'] ?? '') !== '' ? 'checked' : '' ?>>
            Aktiv (wird beim Import berücksichtigt)
        </label>
        <button type="submit">Speichern</button>
        <a href="/admin/import-quellen">Abbrechen</a>
    </form>
</section>
