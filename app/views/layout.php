<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Vereinskalender') ?></title>
    <link rel="stylesheet" href="/css/app.css?v=<?= e($version) ?>">
    <?php if (($colorCss ?? '') !== ''): ?>
        <style><?= $colorCss /* built from palette-validated hex values */ ?></style>
    <?php endif; ?>
</head>
<body>
<header class="site-header">
    <h1><a href="/" class="brand">Vereinskalender</a></h1>
    <nav class="main-nav">
        <a href="/belegung">Platzbelegung</a>
        <a href="/spielplan">Spielplan</a>
        <a href="/verfuegbarkeit">Verfügbarkeit</a>
    </nav>
</header>
<main>
    <?= $content ?>
</main>
<?php if (($appData ?? null) !== null): ?>
    <script type="application/json" id="app-data"><?=
        json_encode($appData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
    ?></script>
<?php endif; ?>
<script src="/js/app.js?v=<?= e($version) ?>" type="module"></script>
<?php foreach ($scripts ?? [] as $script): ?>
    <script src="<?= e($script) ?>?v=<?= e($version) ?>"></script>
<?php endforeach; ?>
</body>
</html>
