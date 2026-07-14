<section class="narrow">
    <h2>Event #<?= e($event->id) ?></h2>
    <table>
        <tbody>
            <tr><th>Aggregat</th><td><?= e($event->aggregateType->value) ?> #<?= e($event->aggregateId) ?></td></tr>
            <tr><th>Typ</th><td><?= e($event->eventType->value) ?></td></tr>
            <tr><th>Name</th><td><?= e($event->editorName) ?></td></tr>
            <tr><th>IP</th><td><?= e($event->ip !== '' ? $event->ip : '– (anonymisiert)') ?></td></tr>
            <tr><th>Quelle</th><td><?= e($event->source->value) ?></td></tr>
            <tr><th>Erstellt</th><td><?= e($event->erstelltAm) ?></td></tr>
            <?php if ($event->korrekturVonEventId !== null): ?>
                <tr><th>Korrektur von</th><td><a href="/admin/events/<?= e($event->korrekturVonEventId) ?>">#<?= e($event->korrekturVonEventId) ?></a></td></tr>
            <?php endif; ?>
            <?php if ($event->excludedAt !== null): ?>
                <tr><th>Ausgeschlossen</th><td><?= e($event->excludedAt) ?> von <?= e($event->excludedVon ?? '') ?>: <?= e($event->excludedGrund ?? '') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <h3>Payload</h3>
    <pre class="payload"><?= e(json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>

    <?php if ($event->excludedAt === null): ?>
        <h3>Ausschließen</h3>
        <form method="post" action="/admin/events/<?= e($event->id) ?>/ausschliessen">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Grund <input type="text" name="grund" required maxlength="255"></label>
            <button type="submit" class="button">Event ausschließen</button>
        </form>

        <h3>Korrigieren</h3>
        <p>Schließt das Original aus und legt eine korrigierte Kopie an (Payload = Vollbild).</p>
        <form method="post" action="/admin/events/<?= e($event->id) ?>/korrigieren">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Payload (JSON)
                <textarea name="payload" rows="10" required><?= e(json_encode($event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
            </label>
            <button type="submit" class="button">Korrektur speichern</button>
        </form>
    <?php else: ?>
        <form method="post" action="/admin/events/<?= e($event->id) ?>/wiederherstellen">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="button">Ausschluss aufheben</button>
        </form>
    <?php endif; ?>

    <p><a href="/admin/events">Zurück zur Historie</a></p>
</section>
