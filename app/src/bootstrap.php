<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Container;
use App\Http\Kernel;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\Support\Version;

// The ONLY place for global runtime setup (timezone convention: everything
// is stored and interpreted as Europe/Berlin, see CLAUDE.md section 12).
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__, 2);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);
$config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
$version = Version::fromFile($paths->versionFile());

$container = new Container($config, $paths, $version);

$router = new Router();
(require __DIR__ . '/routes.php')($router, $container);

// No PDO connection here: the container opens one lazily when a route
// actually needs the database.
return new Kernel(
    router: $router,
    staticFiles: new StaticFileHandler($paths->publicDir(), longCache: !$version->isDev()),
    view: $container->view(),
    debug: $config->debug,
);
