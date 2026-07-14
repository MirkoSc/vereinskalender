<section class="narrow">
    <h2><?= e($title) ?></h2>
    <p>
        Bearbeiten: <a href="/admin/seiten/impressum">Impressum</a> ·
        <a href="/admin/seiten/datenschutz">Datenschutzerklärung</a> ·
        Vorschau: <a href="/<?= e($page['key']) ?>" target="_blank" rel="noopener">/<?= e($page['key']) ?></a>
    </p>
    <form method="post" action="/admin/seiten/<?= e($page['key']) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Titel <input type="text" name="titel" value="<?= e($page['titel']) ?>" required maxlength="100"></label>
        <label>Inhalt (Markdown: # Überschrift, - Liste, **fett**, [Link](https://…))
            <textarea name="inhalt" rows="20"><?= e($page['inhalt']) ?></textarea>
        </label>
        <button type="submit" class="button">Speichern</button>
    </form>
</section>
