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

    <?php if (($homePitchRules ?? null) !== null): ?>
        <h3>Saisonale Heimspielstätte</h3>
        <p>
            Heimspiele werden beim Import automatisch dem im Zeitraum gültigen Platz zugeordnet
            (Gültig-ab/-bis jeweils einschließlich). Eine manuelle Platz-Zuordnung am Spiel bleibt
            immer unangetastet; ohne passende Regel gilt der Standard-Platz der Spielstätte.
        </p>
        <?php if ($homePitchRules === []): ?>
            <p>Noch keine Regel hinterlegt.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Platz</th><th>Gültig ab</th><th>Gültig bis</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($homePitchRules as $rule): ?>
                    <tr>
                        <td><?= e($rule['pitch_name'] ?? '') ?></td>
                        <td><?= e(date('d.m.Y', strtotime((string) $rule['gueltig_ab']))) ?></td>
                        <td><?= e(date('d.m.Y', strtotime((string) $rule['gueltig_bis']))) ?></td>
                        <td>
                            <form method="post" action="/admin/heimplaetze/<?= e($rule['id']) ?>/loeschen" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button type="submit" class="linklike danger">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <form method="post" action="/admin/teams/<?= e($teamId) ?>/heimplatz" class="heimplatz-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>
                Platz
                <select name="pitch_id" required>
                    <option value="">– wählen –</option>
                    <?php foreach ($pitches as $pitch): ?>
                        <option value="<?= e($pitch['id']) ?>"><?= e($pitch['name']) ?> (<?= e($pitch['venue_name'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Gültig ab
                <input type="date" name="gueltig_ab" required>
            </label>
            <label>
                Gültig bis
                <input type="date" name="gueltig_bis" required>
            </label>
            <button type="submit">Hinzufügen</button>
        </form>
    <?php endif; ?>
</section>
