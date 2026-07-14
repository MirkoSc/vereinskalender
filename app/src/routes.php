<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\Version;
use App\View\View;

return static function (Router $router, View $view, Version $version): void {
    $router->get('/', static fn(Request $request, array $params): Response => Response::html(
        $view->render('home', ['title' => 'Vereinskalender']),
    ));

    // Also the self-test target of the updater step chain (milestone 5).
    $router->get('/api/health', static fn(Request $request, array $params): Response => Response::json([
        'status' => 'ok',
        'version' => $version->value,
    ]));
};
