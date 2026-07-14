<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Vereinskalender') ?></title>
    <link rel="stylesheet" href="/css/app.css?v=<?= e($version) ?>">
</head>
<body>
<header class="site-header">
    <h1>Vereinskalender</h1>
</header>
<main>
    <?= $content ?>
</main>
<script src="/js/app.js?v=<?= e($version) ?>" type="module"></script>
</body>
</html>
