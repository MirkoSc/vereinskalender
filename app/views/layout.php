<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#328551">
    <title><?= e($title ?? 'Vereinskalender') ?></title>
    <link rel="stylesheet" href="/css/app.css?v=<?= e($version) ?>">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <?php if ($wappenVorhanden): ?>
        <link rel="icon" type="image/png" sizes="32x32" href="/icon/favicon-32.png?v=<?= e($wappenVersion) ?>">
        <link rel="icon" type="image/png" sizes="16x16" href="/icon/favicon-16.png?v=<?= e($wappenVersion) ?>">
        <link rel="apple-touch-icon" sizes="180x180" href="/icon/apple-touch-icon.png?v=<?= e($wappenVersion) ?>">
    <?php endif; ?>
    <?php if (($colorCss ?? '') !== ''): ?>
        <style><?= $colorCss /* built from palette-validated hex values */ ?></style>
    <?php endif; ?>
</head>
<body>
<div id="offline-banner" class="offline-banner" hidden aria-live="polite"></div>
<header class="site-header">
    <h1><a href="/" class="brand">
        <img src="<?= $wappenVorhanden ? '/icon/logo.png?v=' . e($wappenVersion) : '/icon.svg' ?>" alt="" class="brand-logo">
        Vereinskalender
    </a></h1>
    <nav class="main-nav">
        <a href="/kalender">Kalender</a>
        <a href="/verfuegbarkeit">Verfügbarkeit</a>
        <a href="/abonnieren">Abonnieren</a>
        <a href="/legende">Legende</a>
    </nav>
</header>
<main>
    <?= $content ?>
</main>
<footer class="site-footer">
    <a href="/impressum">Impressum</a>
    <a href="/datenschutz">Datenschutz</a>
</footer>
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
