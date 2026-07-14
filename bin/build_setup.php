<?php

declare(strict_types=1);

// Generates setup.php (repo root) from bin/setup.template.php by inlining
// the shared ReleaseDownloader class - the download/verify/extract code of
// setup.php and the self-updater is literally the same (CLAUDE.md
// section 10). CI fails when setup.php is stale.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$classFile = $root . '/app/src/Service/Update/ReleaseDownloader.php';
$templateFile = __DIR__ . '/setup.template.php';
$targetFile = $root . '/setup.php';

$classSource = file_get_contents($classFile);
$template = file_get_contents($templateFile);
if ($classSource === false || $template === false) {
    fwrite(STDERR, "Cannot read input files.\n");
    exit(1);
}

// strip opening tag, strict_types and the namespace declaration; the
// template provides its own namespace block
$classBody = preg_replace(
    ['/^<\?php\s*/', '/declare\(strict_types=1\);\s*/', '/namespace\s+App\\\\Service\\\\Update;\s*/'],
    '',
    $classSource,
);

$setup = str_replace('//__SHARED_CODE__', trim((string) $classBody), $template);

file_put_contents($targetFile, $setup);
echo "setup.php generated (" . strlen($setup) . " bytes)\n";
exit(0);
