<section class="narrow">
    <h2>Projektionen neu aufbauen</h2>
    <p>
        Baut alle fachlichen Tabellen aus dem Event-Verlauf neu auf (Schatten-Tabellen,
        atomarer Tausch am Ende). Ausgeschlossene Events werden dabei nicht mehr angewendet.
    </p>
    <p class="hinweis">
        Während des Rebuilds ist der <strong>Wartungsmodus</strong> aktiv: Besucher sehen eine
        Wartungsseite und können nichts eintragen. Sonst gingen Änderungen, die zwischen dem
        letzten Batch und dem Tabellentausch gespeichert werden, in der Projektion verloren –
        das Event bliebe im Verlauf, der Termin verschwände aber aus dem Kalender.
        Der Modus endet automatisch mit dem Tausch. Wird das Fenster vorher geschlossen,
        bleibt er stehen – dann hier oder über das Banner abbrechen.
    </p>
    <p>
        <button type="button" id="rebuild-start" class="button">Rebuild starten</button>
    </p>
    <form method="post" action="/admin/rebuild/abbrechen" class="inline-form"
          onsubmit="return confirm('Rebuild abbrechen? Die Projektionen bleiben unverändert, der Wartungsmodus wird aufgehoben.')">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="linklike danger">Rebuild abbrechen und Wartungsmodus aufheben</button>
    </form>
    <div id="rebuild-progress" hidden>
        <progress id="rebuild-bar" max="100" value="0"></progress>
        <p id="rebuild-status" aria-live="polite"></p>
    </div>
    <div id="rebuild-report" hidden>
        <h3>Replay-Report</h3>
        <p id="rebuild-report-summary"></p>
        <ul id="rebuild-report-list"></ul>
    </div>
    <?php if (($state ?? null) !== null && $state->done): ?>
        <h3>Letzter Rebuild</h3>
        <p>
            Abgeschlossen (gestartet <?= e($state->startedAt) ?>),
            <?= e($state->processed) ?> Events verarbeitet,
            <?= e(count($state->skipped)) ?> übersprungen.
        </p>
        <?php if ($state->skipped !== []): ?>
            <ul>
                <?php foreach ($state->skipped as $skipped): ?>
                    <li>
                        Event #<?= e($skipped->eventId) ?>
                        (<?= e($skipped->aggregatTyp) ?> #<?= e($skipped->aggregatId) ?>):
                        <?= e($skipped->grund) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>
