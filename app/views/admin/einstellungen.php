<?php use App\Domain\Palette; ?>
<section class="narrow">
    <h2>Einstellungen</h2>
    <form method="post" action="/admin/einstellungen">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>App-Name
            <input type="text" name="app_name" value="<?= e($values['app_name']) ?>" required maxlength="100">
        </label>
        <label>App-Name kurz (Homescreen-Beschriftung, leer = automatisch gekürzt)
            <input type="text" name="app_name_kurz" value="<?= e($values['app_name_kurz']) ?>" maxlength="30">
        </label>
        <p class="hint">
            Bereits als App installierte Kalender (Homescreen) übernehmen einen
            neuen App-Namen erst bei einer Neuinstallation – das ist so vom
            Betriebssystem vorgegeben (wie beim Wappen unten).
        </p>
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
        <label>Spielfrei-Begriffe (kommagetrennt, case-insensitive; leer = Erkennung aus)
            <input type="text" name="spielfrei_begriffe" value="<?= e($values['spielfrei_begriffe']) ?>" maxlength="255">
        </label>
        <p class="hint">
            Ein Feed-Termin ohne Ort, dessen Titel einen dieser Begriffe enthält,
            gilt als spielfrei statt als Auswärtsspiel.
        </p>
        <fieldset>
            <legend>Spielfrei-Farbe</legend>
            <div class="palette">
                <?php foreach (Palette::COLORS as $hex => $label): ?>
                    <label class="palette-option" title="<?= e($label) ?>">
                        <input type="radio" name="spielfrei_farbe" value="<?= e($hex) ?>" <?= $values['spielfrei_farbe'] === $hex ? 'checked' : '' ?>>
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

    <h2>Vereinswappen</h2>
    <?php if ($wappenVorhanden): ?>
        <p>
            <img src="/icon/logo.png?v=<?= e($wappenVersion) ?>" alt="Aktuelles Wappen" style="height: 4rem; width: 4rem; object-fit: contain;">
            <?php if ($wappenHochgeladenAm !== ''): ?>
                <br>Hochgeladen am <?= e($wappenHochgeladenAm) ?>
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p>Noch kein Wappen hochgeladen – es wird der neutrale Platzhalter verwendet.</p>
    <?php endif; ?>
    <form method="post" action="/admin/einstellungen/wappen" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Wappen hochladen (PNG, mind. 32x32, empfohlen mind. 512x512, max. 3 MB)
            <input type="file" name="wappen" accept="image/png" required>
        </label>
        <button type="submit" class="button">Hochladen</button>
    </form>
    <p class="hint">
        Favicon, App-Icon und das Logo oben links werden automatisch aus dem
        Wappen abgeleitet. Bereits als App installierte Kalender (Homescreen)
        übernehmen ein neues Wappen erst bei einer Neuinstallation – das ist
        so vom Betriebssystem vorgegeben.
    </p>
</section>
