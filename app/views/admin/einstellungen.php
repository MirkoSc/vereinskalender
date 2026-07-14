<?php use App\Domain\Palette; ?>
<section class="narrow">
    <h2>Einstellungen</h2>
    <form method="post" action="/admin/einstellungen">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Vereinsname
            <input type="text" name="vereinsname" value="<?= e($values['vereinsname']) ?>" required maxlength="100">
        </label>
        <div class="field-row">
            <label>Nutzungszeiten von <input type="time" name="nutzungszeiten_von" value="<?= e($values['nutzungszeiten_von']) ?>" required></label>
            <label>bis <input type="time" name="nutzungszeiten_bis" value="<?= e($values['nutzungszeiten_bis']) ?>" required></label>
        </div>
        <fieldset>
            <legend>Auswärts-Farbe (Spiele ohne erkannten Heimverein)</legend>
            <div class="palette">
                <?php foreach (Palette::COLORS as $hex => $label): ?>
                    <label class="palette-option" title="<?= e($label) ?>">
                        <input type="radio" name="auswaerts_farbe" value="<?= e($hex) ?>" <?= $values['auswaerts_farbe'] === $hex ? 'checked' : '' ?>>
                        <span class="swatch" style="background: <?= e($hex) ?>"></span>
                        <span class="palette-label"><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <label>Alarm-E-Mail (leer = keine Alarm-Mails; max. 1 Mail pro Thema und Tag)
            <input type="email" name="alarm_email" value="<?= e($values['alarm_email']) ?>">
        </label>
        <label>IP-Adressen anonymisieren nach (Tagen)
            <input type="number" name="ip_aufbewahrung_tage" value="<?= e($values['ip_aufbewahrung_tage']) ?>" min="1" max="365">
        </label>
        <button type="submit" class="button">Speichern</button>
    </form>
</section>
