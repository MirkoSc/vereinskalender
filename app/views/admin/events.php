<section>
    <h2>Event-Historie (<?= e($gesamt) ?> Treffer)</h2>

    <form method="get" action="/admin/events" class="filter-form">
        <label>IP <input type="text" name="ip" value="<?= e($filters['ip'] ?? '') ?>"></label>
        <label>Name <input type="text" name="editor" value="<?= e($filters['editor'] ?? '') ?>"></label>
        <label>Aggregat
            <select name="aggregat_typ">
                <option value="">alle</option>
                <?php foreach (\App\Domain\AggregateType::cases() as $typ): ?>
                    <option value="<?= e($typ->value) ?>" <?= ($filters['aggregat_typ'] ?? '') === $typ->value ? 'selected' : '' ?>><?= e($typ->value) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Typ
            <select name="event_typ">
                <option value="">alle</option>
                <?php foreach (['created', 'updated', 'deleted'] as $typ): ?>
                    <option value="<?= e($typ) ?>" <?= ($filters['event_typ'] ?? '') === $typ ? 'selected' : '' ?>><?= e($typ) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Quelle
            <select name="quelle">
                <option value="">alle</option>
                <?php foreach (['web', 'admin', 'import', 'system'] as $quelle): ?>
                    <option value="<?= e($quelle) ?>" <?= ($filters['quelle'] ?? '') === $quelle ? 'selected' : '' ?>><?= e($quelle) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Von <input type="date" name="von" value="<?= e($filters['von'] ?? '') ?>"></label>
        <label>Bis <input type="date" name="bis" value="<?= e($filters['bis'] ?? '') ?>"></label>
        <label class="checkbox">
            <input type="checkbox" name="nur_ausgeschlossen" value="1" <?= ($filters['nur_ausgeschlossen'] ?? '') === '1' ? 'checked' : '' ?>>
            nur ausgeschlossene
        </label>
        <button type="submit">Filtern</button>
    </form>

    <?php if (($filters['ip'] ?? '') !== '' || ($filters['editor'] ?? '') !== ''): ?>
        <form method="post" action="/admin/events/massenausschluss" class="mass-form"
              onsubmit="return confirm('Wirklich alle passenden Events ausschließen?');">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="ip" value="<?= e($filters['ip'] ?? '') ?>">
            <input type="hidden" name="editor" value="<?= e($filters['editor'] ?? '') ?>">
            <label>Grund <input type="text" name="grund" required maxlength="255"></label>
            <button type="submit" class="button">
                Alle Events <?= ($filters['ip'] ?? '') !== '' ? 'dieser IP' : 'dieses Namens' ?> ausschließen
            </button>
        </form>
    <?php endif; ?>

    <table>
        <thead><tr><th>#</th><th>Zeit</th><th>Aggregat</th><th>Typ</th><th>Name</th><th>IP</th><th>Quelle</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr class="<?= $event['excluded_at'] !== null ? 'ausgeschlossen' : '' ?>">
                <td><a href="/admin/events/<?= e($event['id']) ?>">#<?= e($event['id']) ?></a></td>
                <td><?= e($event['erstellt_am']) ?></td>
                <td><?= e($event['aggregat_typ']) ?> #<?= e($event['aggregat_id']) ?></td>
                <td><?= e($event['event_typ']) ?></td>
                <td><?= e($event['editor_name']) ?></td>
                <td><?= e($event['ip'] !== '' ? $event['ip'] : '–') ?></td>
                <td><?= e($event['quelle']) ?></td>
                <td><?= $event['excluded_at'] !== null ? 'ausgeschlossen' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($seiten > 1): ?>
        <p>
            <?php $query = array_filter($filters, static fn(string $v): bool => $v !== ''); ?>
            <?php for ($i = 1; $i <= min($seiten, 20); $i++): ?>
                <?php if ($i === $seite): ?>
                    <strong><?= e($i) ?></strong>
                <?php else: ?>
                    <a href="/admin/events?<?= e(http_build_query([...$query, 'seite' => $i])) ?>"><?= e($i) ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </p>
    <?php endif; ?>

    <p><a class="button" href="/admin/rebuild">Rebuild ausführen (wendet Ausschlüsse an)</a></p>
</section>
