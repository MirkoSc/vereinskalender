<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="<?= e($csrf) ?>">
    <title><?= e($title ?? 'Admin') ?> – Vereinskalender</title>
    <link rel="stylesheet" href="/css/app.css?v=<?= e($version) ?>">
</head>
<body>
<header class="site-header admin-header">
    <h1>Vereinskalender · Admin</h1>
    <?php if ($showNav ?? false): ?>
        <nav class="admin-nav">
            <a href="/admin">Übersicht</a>
            <a href="/admin/bereiche">Bereiche</a>
            <a href="/admin/teams">Teams</a>
            <a href="/admin/plaetze">Plätze</a>
            <a href="/admin/spielstaetten">Spielstätten</a>
            <a href="/admin/import-quellen">Import</a>
            <a href="/admin/events">Events</a>
            <a href="/admin/saison">Saison</a>
            <a href="/admin/rebuild">Rebuild</a>
            <a href="/admin/backups">Backups</a>
            <a href="/admin/update">Update</a>
            <a href="/admin/seiten/impressum">Seiten</a>
            <a href="/admin/einstellungen">Einstellungen</a>
            <form method="post" action="/admin/logout" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="linklike">Abmelden (<?= e($adminUsername ?? '') ?>)</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main>
    <?php if (($flash ?? null) !== null): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endif; ?>
    <?= $content ?>
</main>
<script src="/js/admin.js?v=<?= e($version) ?>" type="module"></script>
</body>
</html>
