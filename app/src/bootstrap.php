<?php

declare(strict_types=1);

use App\Config\Config;
use App\Config\Paths;
use App\Container;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\Installer\InstallController;
use App\Support\Version;
use App\View\View;

// The ONLY place for global runtime setup (timezone convention: everything
// is stored and interpreted as Europe/Berlin, see CLAUDE.md section 12).
error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

$releaseRoot = dirname(__DIR__, 2);

require $releaseRoot . '/vendor/autoload.php';

$paths = new Paths($releaseRoot);
$version = Version::fromFile($paths->versionFile());
$configFile = getenv('APP_CONFIG_FILE') ?: $paths->configFile();

// Install mode (CLAUDE.md section 10): while shared/config.php is missing,
// ONLY the installer routes exist; everything else redirects there.
if (!is_file($configFile)) {
    $view = new View($paths->viewsDir(), $version->value);
    $installer = new InstallController($view, $paths);

    $router = new Router();
    $router->get('/install', static fn(Request $r, array $p) => $installer->form($r));
    $router->post('/install', static fn(Request $r, array $p) => $installer->submit($r));
    $router->post('/install/restore-step', static fn(Request $r, array $p) => $installer->restoreStep($r));
    $router->get('/{rest:.*}', static fn(Request $r, array $p) => Response::redirect('/install'));

    return new Kernel(
        router: $router,
        staticFiles: new StaticFileHandler($paths->publicDir(), longCache: false),
        view: $view,
        debug: true,
    );
}

$config = Config::fromFile($configFile);
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
