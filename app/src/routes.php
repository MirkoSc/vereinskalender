<?php

declare(strict_types=1);

use App\Container;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;

return static function (Router $router, Container $c): void {
    // ---- public ----

    $router->get('/', static fn(Request $request, array $params): Response => Response::html(
        $c->view()->render('home', ['title' => 'Vereinskalender']),
    ));

    // Also the self-test target of the updater step chain (milestone 5).
    $router->get('/api/health', static fn(Request $request, array $params): Response => Response::json([
        'status' => 'ok',
        'version' => $c->version->value,
    ]));

    // ---- admin ----

    // Starts the session; for POST requests the CSRF token is checked.
    // $requireAdmin = false is used for login/setup (no admin session yet).
    $guard = static function (\Closure $handler, bool $requireAdmin = true) use ($c): \Closure {
        return static function (Request $request, array $params) use ($handler, $requireAdmin, $c): ResponseInterface {
            $session = $c->session();
            $session->start();

            if ($requireAdmin && !$session->isAdmin()) {
                return Response::redirect('/admin/login');
            }
            if ($request->method === HttpMethod::Post && !$session->checkCsrf($request)) {
                return new Response(403, ['Content-Type' => 'text/plain; charset=utf-8'], 'Ungültiges CSRF-Token');
            }

            return $handler($request, $params);
        };
    };

    $router->get('/admin/login', $guard(fn(Request $r, array $p) => $c->authController()->showLogin($r), false));
    $router->post('/admin/login', $guard(fn(Request $r, array $p) => $c->authController()->login($r), false));
    $router->get('/admin/setup', $guard(fn(Request $r, array $p) => $c->authController()->showSetup($r), false));
    $router->post('/admin/setup', $guard(fn(Request $r, array $p) => $c->authController()->setup($r), false));
    $router->post('/admin/logout', $guard(fn(Request $r, array $p) => $c->authController()->logout($r), false));

    $router->get('/admin', $guard(fn(Request $r, array $p) => $c->dashboardController()->index($r)));

    $router->get('/admin/teams', $guard(fn(Request $r, array $p) => $c->teamController()->index($r)));
    $router->get('/admin/teams/neu', $guard(fn(Request $r, array $p) => $c->teamController()->createForm($r)));
    $router->post('/admin/teams', $guard(fn(Request $r, array $p) => $c->teamController()->create($r)));
    $router->get('/admin/teams/{id:\d+}', $guard(fn(Request $r, array $p) => $c->teamController()->editForm($r, $p)));
    $router->post('/admin/teams/{id:\d+}', $guard(fn(Request $r, array $p) => $c->teamController()->update($r, $p)));
    $router->post('/admin/teams/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->teamController()->delete($r, $p)));

    $router->get('/admin/plaetze', $guard(fn(Request $r, array $p) => $c->pitchController()->index($r)));
    $router->get('/admin/plaetze/neu', $guard(fn(Request $r, array $p) => $c->pitchController()->createForm($r)));
    $router->post('/admin/plaetze', $guard(fn(Request $r, array $p) => $c->pitchController()->create($r)));
    $router->get('/admin/plaetze/{id:\d+}', $guard(fn(Request $r, array $p) => $c->pitchController()->editForm($r, $p)));
    $router->post('/admin/plaetze/{id:\d+}', $guard(fn(Request $r, array $p) => $c->pitchController()->update($r, $p)));
    $router->post('/admin/plaetze/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->pitchController()->delete($r, $p)));

    $router->get('/admin/spielstaetten', $guard(fn(Request $r, array $p) => $c->venueController()->index($r)));
    $router->get('/admin/spielstaetten/neu', $guard(fn(Request $r, array $p) => $c->venueController()->createForm($r)));
    $router->post('/admin/spielstaetten', $guard(fn(Request $r, array $p) => $c->venueController()->create($r)));
    $router->get('/admin/spielstaetten/{id:\d+}', $guard(fn(Request $r, array $p) => $c->venueController()->editForm($r, $p)));
    $router->post('/admin/spielstaetten/{id:\d+}', $guard(fn(Request $r, array $p) => $c->venueController()->update($r, $p)));
    $router->post('/admin/spielstaetten/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->venueController()->delete($r, $p)));
    $router->post('/admin/spielstaetten/{id:\d+}/begriffe', $guard(fn(Request $r, array $p) => $c->venueController()->addBegriff($r, $p)));
    $router->post('/admin/begriffe/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->venueController()->deleteBegriff($r, $p)));

    $router->get('/admin/rebuild', $guard(fn(Request $r, array $p) => $c->rebuildController()->page($r)));
    $router->post('/admin/rebuild/start', $guard(fn(Request $r, array $p) => $c->rebuildController()->start($r)));
    $router->post('/admin/rebuild/step', $guard(fn(Request $r, array $p) => $c->rebuildController()->step($r)));
};
