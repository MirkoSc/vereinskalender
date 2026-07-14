<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Http\Kernel;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\Support\Version;
use App\View\View;

// The ONLY place for global runtime setup (timezone convention: everything
// is stored and interpreted as Europe/Berlin, see CLAUDE.md section 12).
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__, 2);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);
$config = Config::fromFile(getenv('APP_CONFIG_FILE') ?: $paths->configFile());
$version = Version::fromFile($paths->versionFile());

$view = new View($paths->viewsDir(), $version->value);

$router = new Router();
(require __DIR__ . '/routes.php')($router, $view, $version);

// No PDO connection here: routes request one lazily when they need it.
return new Kernel(
    router: $router,
    staticFiles: new StaticFileHandler($paths->publicDir(), longCache: !$version->isDev()),
    view: $view,
    debug: $config->debug,
);
