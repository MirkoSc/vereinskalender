<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Database\ConnectionFactory;
use App\Service\Migration\Migration;
use App\Service\Migration\Migrator;

// Dev/CLI entry point only — the installer and self-updater use the
// Migrator class directly.
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
    $migrator = new Migrator(ConnectionFactory::create($config), $paths->migrationsDir());

    $pending = $migrator->pending();
    if ($pending === []) {
        echo sprintf("Schema version %d, nothing to apply.\n", $migrator->currentVersion());
        exit(0);
    }

    $result = $migrator->migrate(static function (Migration $migration): void {
        echo sprintf("Applying %03d_%s ...\n", $migration->version, $migration->name);
    });

    echo sprintf(
        "Applied %d migration(s), schema version %d -> %d.\n",
        count($result->applied),
        $result->fromVersion,
        $result->toVersion,
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
