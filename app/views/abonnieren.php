<section class="narrow">
    <h2>Kalender abonnieren</h2>
    <p>
        Die Termine lassen sich als Kalender-Abo in Google Kalender, Apple Kalender (iOS/macOS)
        oder Outlook einbinden. Verlegungen wandern im Abo automatisch mit.
    </p>
    <p class="hint">
        Hinweis: Google und Outlook aktualisieren Abos nur alle paar Stunden. Für kurzfristige
        Änderungen (Platzsperrungen, Spielabsagen) ist die Push-Benachrichtigung
        (Glocke im Kalender) der schnellere Kanal.
    </p>

    <label>
        Feed auswählen
        <select id="feed-select">
            <option value="/export/spiele.ics">Alle Spiele</option>
            <?php foreach ($teams as $team): ?>
                <option value="/export/team/<?= e($team['id']) ?>.ics">Spiele <?= e($team['name']) ?> (<?= e($team['bereich']) ?>)</option>
            <?php endforeach; ?>
            <?php foreach ($pitches as $pitch): ?>
                <option value="/export/platz/<?= e($pitch['id']) ?>.ics">Belegung <?= e($pitch['name']) ?> (<?= e($pitch['venue_name'] ?? '') ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>

    <div class="abo-links">
        <p><a id="webcal-link" class="button" href="#">Apple Kalender / iOS (webcal)</a></p>
        <p><a id="google-link" class="button" href="#" target="_blank" rel="noopener">Google Kalender</a></p>
        <p>
            <button type="button" id="copy-url" class="button">Feed-URL kopieren</button>
            <span id="copy-feedback" aria-live="polite"></span>
        </p>
    </div>

    <h3>Outlook</h3>
    <ol>
        <li>Feed-URL oben kopieren.</li>
        <li>In Outlook: Kalender → Kalender hinzufügen → „Aus dem Internet abonnieren".</li>
        <li>URL einfügen und bestätigen.</li>
    </ol>
</section>

<script>
    (() => {
        const select = document.querySelector('#feed-select');
        const webcal = document.querySelector('#webcal-link');
        const google = document.querySelector('#google-link');
        const copyButton = document.querySelector('#copy-url');
        const feedback = document.querySelector('#copy-feedback');

        const update = () => {
            const httpUrl = window.location.origin + select.value;
            const webcalUrl = httpUrl.replace(/^https?:/, 'webcal:');
            webcal.href = webcalUrl;
            google.href = 'https://calendar.google.com/calendar/r?cid=' + encodeURIComponent(webcalUrl);
        };

        select.addEventListener('change', update);
        copyButton.addEventListener('click', async () => {
            await navigator.clipboard.writeText(window.location.origin + select.value);
            feedback.textContent = 'Kopiert!';
            setTimeout(() => { feedback.textContent = ''; }, 2000);
        });
        update();
    })();
</script>
