<section class="narrow">
    <h2>Update</h2>

    <form method="post" action="/admin/update/kanal" class="channel-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Update-Kanal
            <select name="kanal">
                <option value="stable" <?= $kanal === 'stable' ? 'selected' : '' ?>>stable (nur reguläre Releases)</option>
                <option value="beta" <?= $kanal === 'beta' ? 'selected' : '' ?>>beta (auch Pre-Releases, für die Testinstanz)</option>
            </select>
        </label>
        <button type="submit">Kanal speichern</button>
    </form>

    <p>
        <button type="button" id="update-check" class="button">Nach Updates suchen</button>
        <button type="button" id="update-start" class="button" hidden>Update starten</button>
    </p>

    <div id="update-progress" hidden>
        <p id="update-status" aria-live="polite"></p>
        <ul id="update-log"></ul>
        <p id="update-error" class="error-message" hidden></p>
        <p id="update-actions" hidden>
            <button type="button" id="update-retry" class="button">Schritt wiederholen</button>
            <button type="button" id="update-rollback" class="linklike danger">Rollback auf vorheriges Release</button>
        </p>
    </div>

    <?php if (($state ?? null) !== null): ?>
        <h3>Letzter Update-Status</h3>
        <p>
            Version <?= e($state->aktuelleVersion) ?>
            <?php if ($state->zielVersion !== null): ?> → <?= e($state->zielVersion) ?><?php endif; ?>,
            Schritt: <?= e($state->abgeschlossenerSchritt ?? '–') ?>,
            <?= $state->fertig ? 'abgeschlossen' : 'offen' ?>
        </p>
        <?php if ($state->fehler !== null): ?>
            <p class="error-message"><?= e($state->fehler) ?></p>
        <?php endif; ?>
        <?php if ($state->meldungen !== []): ?>
            <ul>
                <?php foreach ($state->meldungen as $meldung): ?>
                    <li><?= e($meldung) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="/admin/update/reset" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="linklike">Status zurücksetzen</button>
        </form>
    <?php endif; ?>
</section>
