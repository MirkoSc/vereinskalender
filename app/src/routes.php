<?php

declare(strict_types=1);

use App\Container;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;

return static function (Router $router, Container $c): void {
    // ---- public pages (reading never starts a session) ----

    $router->get('/', static fn(Request $r, array $p): Response => $c->publicController()->home($r));
    $router->get('/belegung', static fn(Request $r, array $p): Response => $c->publicController()->belegung($r));
    $router->get('/spielplan', static fn(Request $r, array $p): Response => $c->publicController()->spielplan($r));
    $router->get('/verfuegbarkeit', static fn(Request $r, array $p): Response => $c->publicController()->verfuegbarkeit($r));
    $router->get('/abonnieren', static fn(Request $r, array $p): Response => $c->publicController()->abonnieren($r));
    $router->get('/{key:impressum|datenschutz}', static fn(Request $r, array $p): Response => $c->publicController()->seite($r, $p));
    $router->get('/sw.js', static fn(Request $r, array $p): Response => $c->publicController()->serviceWorker($r));

    // ---- calendar subscription feeds (stable URLs, CLAUDE.md section 9) ----

    $router->get('/export/spiele.ics', static fn(Request $r, array $p): Response => $c->exportController()->alleSpiele($r));
    $router->get('/export/team/{id:\d+}.ics', static fn(Request $r, array $p): Response => $c->exportController()->teamSpiele($r, $p));
    $router->get('/export/platz/{id:\d+}.ics', static fn(Request $r, array $p): Response => $c->exportController()->platzBelegung($r, $p));

    // ---- public read API ----

    $router->get('/api/events', static fn(Request $r, array $p): Response => $c->eventsApiController()->events($r));
    $router->get('/api/verfuegbarkeit', static fn(Request $r, array $p): Response => $c->eventsApiController()->verfuegbarkeit($r));
    $router->get('/api/offline-bundle', static fn(Request $r, array $p): Response => $c->eventsApiController()->offlineBundle($r));
    $router->get('/api/push/vapid', static fn(Request $r, array $p): Response => $c->pushApiController()->vapidKey($r));

    // Also the self-test target of the updater step chain (milestone 5).
    $router->get('/api/health', static fn(Request $request, array $params): Response => Response::json([
        'status' => 'ok',
        'version' => $c->version->value,
    ]));

    // ---- public write API (CLAUDE.md section 6) ----
    // Session only for the CSRF token; every write must carry an
    // editor_name (rejected otherwise, deliberately not verified further).

    $router->get('/api/csrf', static function (Request $r, array $p) use ($c): Response {
        $c->session()->start();

        return $c->bookingApiController()->csrf($r);
    });

    // CSRF + rate limit (~30 writes/min per IP, CLAUDE.md section 6);
    // $requireName = false for endpoints that are no content writes
    $publicWrite = static function (\Closure $handler, bool $requireName = true) use ($c): \Closure {
        return static function (Request $request, array $params) use ($handler, $requireName, $c): ResponseInterface {
            $session = $c->session();
            $session->start();
            if (!$session->checkCsrf($request)) {
                return Response::json(['fehler' => ['csrf' => 'Ungültiges oder fehlendes CSRF-Token.']], 403);
            }
            if (!$c->rateLimiter()->allow($request->ip)) {
                return Response::json(['fehler' => ['rate' => 'Zu viele Änderungen – bitte kurz warten.']], 429);
            }
            if ($requireName && trim((string) ($request->post['editor_name'] ?? '')) === '') {
                return Response::json(['fehler' => ['editor_name' => 'Bitte zuerst einen Namen angeben.']], 422);
            }

            return $handler($request, $params);
        };
    };

    $router->post('/api/slots/pruefen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->check($r)));
    $router->post('/api/slots', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->createSlot($r)));
    $router->post('/api/slots/{id:\d+}', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->updateSlot($r, $p)));
    $router->post('/api/slots/{id:\d+}/loeschen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->deleteSlot($r, $p)));
    $router->post('/api/slots/{id:\d+}/ausfall', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->addException($r, $p)));
    $router->post('/api/ausnahmen/{id:\d+}/loeschen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->deleteException($r, $p)));
    $router->post('/api/sperrungen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->createRestriction($r)));
    $router->post('/api/sperrungen/{id:\d+}/loeschen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->deleteRestriction($r, $p)));
    $router->post('/api/spiele/{id:\d+}/platz', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->assignPitch($r, $p)));
    $router->post('/api/push/subscribe', $publicWrite(fn(Request $r, array $p) => $c->pushApiController()->subscribe($r), false));
    $router->post('/api/push/unsubscribe', $publicWrite(fn(Request $r, array $p) => $c->pushApiController()->unsubscribe($r), false));

    // navigator.sendBeacon cannot send headers: no CSRF here, but the
    // endpoint only increments whitelisted counters and is rate-limited
    $router->post('/api/stat', static function (Request $r, array $p) use ($c): ResponseInterface {
        if (!$c->rateLimiter()->allow($r->ip)) {
            return Response::json(['fehler' => ['rate' => 'Zu viele Anfragen.']], 429);
        }

        return $c->statController()->beacon($r);
    });

    // ---- cron (secret token, no session; CLAUDE.md section 7) ----

    $router->get('/cron/import', static fn(Request $r, array $p): Response => $c->cronController()->import($r));

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

    $router->get('/admin/import-quellen', $guard(fn(Request $r, array $p) => $c->importSourceController()->index($r)));
    $router->get('/admin/import-quellen/neu', $guard(fn(Request $r, array $p) => $c->importSourceController()->createForm($r)));
    $router->post('/admin/import-quellen', $guard(fn(Request $r, array $p) => $c->importSourceController()->create($r)));
    $router->get('/admin/import-quellen/{id:\d+}', $guard(fn(Request $r, array $p) => $c->importSourceController()->editForm($r, $p)));
    $router->post('/admin/import-quellen/{id:\d+}', $guard(fn(Request $r, array $p) => $c->importSourceController()->update($r, $p)));
    $router->post('/admin/import-quellen/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->importSourceController()->delete($r, $p)));
    $router->post('/admin/import/run', $guard(fn(Request $r, array $p) => $c->importSourceController()->run($r)));

    $router->get('/admin/rebuild', $guard(fn(Request $r, array $p) => $c->rebuildController()->page($r)));
    $router->post('/admin/rebuild/start', $guard(fn(Request $r, array $p) => $c->rebuildController()->start($r)));
    $router->post('/admin/rebuild/step', $guard(fn(Request $r, array $p) => $c->rebuildController()->step($r)));

    $router->get('/admin/backups', $guard(fn(Request $r, array $p) => $c->backupController()->index($r)));
    $router->post('/admin/backups', $guard(fn(Request $r, array $p) => $c->backupController()->create($r)));
    $router->get('/admin/backups/{name}', $guard(fn(Request $r, array $p) => $c->backupController()->download($r, $p)));

    $router->get('/admin/update', $guard(fn(Request $r, array $p) => $c->updateController()->page($r)));
    $router->post('/admin/update/kanal', $guard(fn(Request $r, array $p) => $c->updateController()->setChannel($r)));
    $router->post('/admin/update/reset', $guard(fn(Request $r, array $p) => $c->updateController()->resetState($r)));
    $router->post('/admin/update/{schritt:[a-z]+}', $guard(fn(Request $r, array $p) => $c->updateController()->step($r, $p)));

    $router->get('/admin/events', $guard(fn(Request $r, array $p) => $c->eventHistoryController()->index($r)));
    $router->get('/admin/events/{id:\d+}', $guard(fn(Request $r, array $p) => $c->eventHistoryController()->detail($r, $p)));
    $router->post('/admin/events/{id:\d+}/ausschliessen', $guard(fn(Request $r, array $p) => $c->eventHistoryController()->exclude($r, $p)));
    $router->post('/admin/events/{id:\d+}/wiederherstellen', $guard(fn(Request $r, array $p) => $c->eventHistoryController()->undoExclude($r, $p)));
    $router->post('/admin/events/{id:\d+}/korrigieren', $guard(fn(Request $r, array $p) => $c->eventHistoryController()->correct($r, $p)));
    $router->post('/admin/events/massenausschluss', $guard(fn(Request $r, array $p) => $c->eventHistoryController()->excludeMass($r)));

    $router->get('/admin/saison', $guard(fn(Request $r, array $p) => $c->saisonController()->page($r)));
    $router->post('/admin/saison/slots-kopieren', $guard(fn(Request $r, array $p) => $c->saisonController()->copySlots($r)));

    $router->get('/admin/seiten/{key:impressum|datenschutz}', $guard(fn(Request $r, array $p) => $c->pageAdminController()->form($r, $p)));
    $router->post('/admin/seiten/{key:impressum|datenschutz}', $guard(fn(Request $r, array $p) => $c->pageAdminController()->save($r, $p)));

    $router->get('/admin/einstellungen', $guard(fn(Request $r, array $p) => $c->settingsController()->form($r)));
    $router->post('/admin/einstellungen', $guard(fn(Request $r, array $p) => $c->settingsController()->save($r)));
};
