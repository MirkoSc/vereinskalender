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
use App\Support\FileLogger;
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
        // NOT debug mode, even though this is the install step: with debug
        // on, an unhandled exception renders the full exception string into
        // the browser - and with zend.exception_ignore_args off a trace
        // carries function arguments, which on this path means the database
        // password the user just typed into InstallController::connect().
        // The installer surfaces the errors that matter (the connection
        // test) in the form itself; everything else goes to the log below.
        debug: false,
        logger: new FileLogger($paths->sharedDir() . '/var/log/app.log'),
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
    logger: new FileLogger($paths->sharedDir() . '/var/log/app.log'),
);
