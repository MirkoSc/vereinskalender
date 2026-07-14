<section class="narrow">
    <h2>Projektionen neu aufbauen</h2>
    <p>
        Baut alle fachlichen Tabellen aus dem Event-Verlauf neu auf (Schatten-Tabellen,
        atomarer Tausch am Ende). Ausgeschlossene Events werden dabei nicht mehr angewendet.
    </p>
    <p>
        <button type="button" id="rebuild-start" class="button">Rebuild starten</button>
    </p>
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
