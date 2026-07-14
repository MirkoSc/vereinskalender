<section>
    <h2>Betrieb</h2>
    <?php $m = $monitoring; ?>
    <p class="monitor monitor-<?= e($m['ampel']) ?>">
        <?php if ($m['letzter_import_minuten'] === null): ?>
            Noch kein Import gelaufen<?= $m['aktive_quellen'] === 0 ? ' (keine aktiven Quellen)' : ' – Cronjob eingerichtet?' ?>
        <?php else: ?>
            Letzter Import: vor <?= e($m['letzter_import_minuten']) ?> Min.
            <?php if ($m['ampel'] === 'rot'): ?>(Cronjob prüfen!)<?php endif; ?>
        <?php endif; ?>
    </p>
    <?php foreach ($m['warnungen'] as $warnung): ?>
        <p class="error-message">⚠ <?= e($warnung) ?></p>
    <?php endforeach; ?>

    <h2>Übersicht</h2>
    <ul class="stat-list">
        <li><a href="/admin/teams"><strong><?= e($teamCount) ?></strong> Teams</a></li>
        <li><a href="/admin/plaetze"><strong><?= e($pitchCount) ?></strong> Plätze</a></li>
        <li><a href="/admin/spielstaetten"><strong><?= e($venueCount) ?></strong> Spielstätten</a></li>
        <li><a href="/admin/events"><strong><?= e($eventCount) ?></strong> aktive Events</a></li>
        <li><strong><?= e($pushCount) ?></strong> Push-Abos</li>
    </ul>

    <h2>Nutzung</h2>
    <table class="narrow">
        <thead><tr><th></th><th>Heute</th><th>7 Tage</th><th>30 Tage</th></tr></thead>
        <tbody>
            <tr><td>Seitenaufrufe</td><td><?= e($seitenaufrufe['heute']) ?></td><td><?= e($seitenaufrufe['tage7']) ?></td><td><?= e($seitenaufrufe['tage30']) ?></td></tr>
            <tr><td>API-Abrufe</td><td><?= e($apiAbrufe['heute']) ?></td><td><?= e($apiAbrufe['tage7']) ?></td><td><?= e($apiAbrufe['tage30']) ?></td></tr>
            <tr><td>ICS-Feed-Abrufe</td><td><?= e($feedAbrufe['heute']) ?></td><td><?= e($feedAbrufe['tage7']) ?></td><td><?= e($feedAbrufe['tage30']) ?></td></tr>
        </tbody>
    </table>

    <?php if ($tagesverlauf !== []): ?>
        <h3>Seitenaufrufe – letzte 14 Tage</h3>
        <?php $max = max(array_column($tagesverlauf, 'anzahl')) ?: 1; ?>
        <div class="bars">
            <?php foreach ($tagesverlauf as $tag): ?>
                <div class="bar" title="<?= e($tag['datum']) ?>: <?= e($tag['anzahl']) ?>">
                    <span class="bar-fill" style="height: <?= e((int) round($tag['anzahl'] / $max * 100)) ?>%"></span>
                    <span class="bar-label"><?= e(substr($tag['datum'], 8)) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="dashboard-columns">
        <div>
            <h3>Top-Routen (30 Tage)</h3>
            <?php if ($topRouten === []): ?><p>Noch keine Daten.</p><?php else: ?>
                <ul>
                    <?php foreach ($topRouten as $route): ?>
                        <li><?= e($route['dimension']) ?>: <?= e($route['anzahl']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div>
            <h3>ICS-Feeds (30 Tage)</h3>
            <?php if ($topFeeds === []): ?><p>Noch keine Abrufe.</p><?php else: ?>
                <ul>
                    <?php foreach ($topFeeds as $feed): ?>
                        <li><?= e($feed['dimension']) ?>: <?= e($feed['anzahl']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div>
            <h3>Features (30 Tage)</h3>
            <ul>
                <?php foreach ($featureZaehler as $name => $anzahl): ?>
                    <li><?= e($name) ?>: <?= e($anzahl) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</section>
