<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Container;
use App\Support\Version;

// CLI entry point for dev/manual runs. The production cron calls the
// /cron/import route via HTTP (bin/ is not web-accessible).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);

try {
    $config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
    $container = new Container($config, $paths, Version::fromFile($paths->versionFile()));

    $results = $container->icsImportService()->runAll();
    if ($results === []) {
        echo "No active import sources.\n";
        exit(0);
    }

    $failed = false;
    foreach ($results as $result) {
        if ($result->ok) {
            echo sprintf(
                "Source #%d: %d new, %d updated, %d cancelled, %d unchanged\n",
                $result->sourceId,
                $result->inserted,
                $result->updated,
                $result->cancelled,
                $result->skipped,
            );
        } else {
            $failed = true;
            echo sprintf("Source #%d: ERROR - %s\n", $result->sourceId, (string) $result->fehlertext);
        }
    }

    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
    exit(1);
}
