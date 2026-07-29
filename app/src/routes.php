<?php

declare(strict_types=1);

use App\Container;
use App\Domain\EventContext;
use App\Http\HttpMethod;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Router;

return static function (Router $router, Container $c): void {
    // ---- public pages (reading never starts a session) ----

    $router->get('/', static fn(Request $r, array $p): Response => $c->publicController()->home($r));
    $router->get('/kalender', static fn(Request $r, array $p): Response => $c->publicController()->kalender($r));
    // Issue #37: Spielplan + Platzbelegung zusammengeführt - Alt-Routen
    // leiten (mit Query-String) auf /kalender um, damit geteilte Links
    // funktionsfähig bleiben.
    $router->get('/belegung', static fn(Request $r, array $p): Response => $c->publicController()->belegung($r));
    $router->get('/spielplan', static fn(Request $r, array $p): Response => $c->publicController()->spielplan($r));
    $router->get('/verfuegbarkeit', static fn(Request $r, array $p): Response => $c->publicController()->verfuegbarkeit($r));
    $router->get('/abonnieren', static fn(Request $r, array $p): Response => $c->publicController()->abonnieren($r));
    $router->get('/legende', static fn(Request $r, array $p): Response => $c->publicController()->legende($r));
    $router->get('/{key:impressum|datenschutz}', static fn(Request $r, array $p): Response => $c->publicController()->seite($r, $p));
    $router->get('/sw.js', static fn(Request $r, array $p): Response => $c->publicController()->serviceWorker($r));
    $router->get('/manifest.webmanifest', static fn(Request $r, array $p): Response => $c->publicController()->manifest($r));
    $router->get(
        '/icon/{name:favicon-16\.png|favicon-32\.png|apple-touch-icon\.png|icon-192\.png|icon-512\.png|logo\.png}',
        static fn(Request $r, array $p): ResponseInterface => $c->publicController()->icon($r, $p),
    );

    // ---- calendar subscription feeds (stable URLs, CLAUDE.md section 9) ----

    $router->get('/export/spiele.ics', static fn(Request $r, array $p): Response => $c->exportController()->alleSpiele($r));
    $router->get('/export/team/{id:\d+}.ics', static fn(Request $r, array $p): Response => $c->exportController()->teamSpiele($r, $p));
    $router->get('/export/platz/{id:\d+}.ics', static fn(Request $r, array $p): Response => $c->exportController()->platzBelegung($r, $p));

    // ---- public read API ----

    $router->get('/api/events', static fn(Request $r, array $p): Response => $c->eventsApiController()->events($r));
    $router->get('/api/verfuegbarkeit', static fn(Request $r, array $p): Response => $c->eventsApiController()->verfuegbarkeit($r));
    $router->get('/api/offline-bundle', static fn(Request $r, array $p): Response => $c->eventsApiController()->offlineBundle($r));
    $router->get('/api/sperrungen/{id:\d+}', static fn(Request $r, array $p): Response => $c->eventsApiController()->restriction($r, $p));
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
            if ($requireName) {
                // Not a check of WHO writes (trust model, CLAUDE.md section
                // 5) but of the field itself: the name reaches
                // event.editor_name VARCHAR(100), and the form's maxlength
                // does not bind an API caller. Without this an over-long
                // name fails the write transaction with a bare 500.
                $editorName = trim((string) ($request->post['editor_name'] ?? ''));
                if ($editorName === '') {
                    return Response::json(['fehler' => ['editor_name' => 'Bitte zuerst einen Namen angeben.']], 422);
                }
                if (mb_strlen($editorName) > EventContext::MAX_EDITOR_NAME) {
                    return Response::json(['fehler' => ['editor_name' => sprintf(
                        'Der Name darf höchstens %d Zeichen lang sein.',
                        EventContext::MAX_EDITOR_NAME,
                    )]], 422);
                }
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
    $router->post('/api/sperrungen/{id:\d+}', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->updateRestriction($r, $p)));
    $router->post('/api/sperrungen/{id:\d+}/loeschen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->deleteRestriction($r, $p)));
    $router->post('/api/spiele/{id:\d+}/platz', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->assignPitch($r, $p)));
    $router->post('/api/spiele/pruefen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->checkMatch($r)));
    $router->post('/api/spiele', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->createMatch($r)));
    $router->post('/api/spiele/{id:\d+}', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->updateMatch($r, $p)));
    $router->post('/api/spiele/{id:\d+}/loeschen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->deleteMatch($r, $p)));
    $router->post('/api/vermietungen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->createVermietung($r)));
    $router->post('/api/vermietungen/{id:\d+}', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->updateVermietung($r, $p)));
    $router->post('/api/vermietungen/{id:\d+}/loeschen', $publicWrite(fn(Request $r, array $p) => $c->bookingApiController()->deleteVermietung($r, $p)));
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

    $router->get('/admin/bereiche', $guard(fn(Request $r, array $p) => $c->bereichController()->index($r)));
    $router->get('/admin/bereiche/neu', $guard(fn(Request $r, array $p) => $c->bereichController()->createForm($r)));
    $router->post('/admin/bereiche', $guard(fn(Request $r, array $p) => $c->bereichController()->create($r)));
    $router->post('/admin/bereiche/sortierung', $guard(fn(Request $r, array $p) => $c->bereichController()->sortierung($r)));
    $router->get('/admin/bereiche/{id:\d+}', $guard(fn(Request $r, array $p) => $c->bereichController()->editForm($r, $p)));
    $router->post('/admin/bereiche/{id:\d+}', $guard(fn(Request $r, array $p) => $c->bereichController()->update($r, $p)));
    $router->post('/admin/bereiche/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->bereichController()->delete($r, $p)));

    $router->get('/admin/teams', $guard(fn(Request $r, array $p) => $c->teamController()->index($r)));
    $router->get('/admin/teams/neu', $guard(fn(Request $r, array $p) => $c->teamController()->createForm($r)));
    $router->post('/admin/teams', $guard(fn(Request $r, array $p) => $c->teamController()->create($r)));
    $router->post('/admin/teams/sortierung', $guard(fn(Request $r, array $p) => $c->teamController()->sortierung($r)));
    $router->get('/admin/teams/{id:\d+}', $guard(fn(Request $r, array $p) => $c->teamController()->editForm($r, $p)));
    $router->post('/admin/teams/{id:\d+}', $guard(fn(Request $r, array $p) => $c->teamController()->update($r, $p)));
    $router->post('/admin/teams/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->teamController()->delete($r, $p)));
    $router->post('/admin/teams/{id:\d+}/heimplatz', $guard(fn(Request $r, array $p) => $c->teamController()->addHomePitch($r, $p)));
    $router->post('/admin/heimplaetze/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->teamController()->deleteHomePitch($r, $p)));

    $router->get('/admin/plaetze', $guard(fn(Request $r, array $p) => $c->pitchController()->index($r)));
    $router->get('/admin/plaetze/neu', $guard(fn(Request $r, array $p) => $c->pitchController()->createForm($r)));
    $router->post('/admin/plaetze', $guard(fn(Request $r, array $p) => $c->pitchController()->create($r)));
    $router->post('/admin/plaetze/sortierung', $guard(fn(Request $r, array $p) => $c->pitchController()->sortierung($r)));
    $router->get('/admin/plaetze/{id:\d+}', $guard(fn(Request $r, array $p) => $c->pitchController()->editForm($r, $p)));
    $router->post('/admin/plaetze/{id:\d+}', $guard(fn(Request $r, array $p) => $c->pitchController()->update($r, $p)));
    $router->post('/admin/plaetze/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->pitchController()->delete($r, $p)));

    $router->get('/admin/spielstaetten', $guard(fn(Request $r, array $p) => $c->venueController()->index($r)));
    $router->get('/admin/spielstaetten/neu', $guard(fn(Request $r, array $p) => $c->venueController()->createForm($r)));
    $router->post('/admin/spielstaetten', $guard(fn(Request $r, array $p) => $c->venueController()->create($r)));
    $router->post('/admin/spielstaetten/sortierung', $guard(fn(Request $r, array $p) => $c->venueController()->sortierung($r)));
    $router->get('/admin/spielstaetten/{id:\d+}', $guard(fn(Request $r, array $p) => $c->venueController()->editForm($r, $p)));
    $router->post('/admin/spielstaetten/{id:\d+}', $guard(fn(Request $r, array $p) => $c->venueController()->update($r, $p)));
    $router->post('/admin/spielstaetten/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->venueController()->delete($r, $p)));
    $router->post('/admin/spielstaetten/{id:\d+}/begriffe', $guard(fn(Request $r, array $p) => $c->venueController()->addBegriff($r, $p)));
    $router->post('/admin/begriffe/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->venueController()->deleteBegriff($r, $p)));

    $router->get('/admin/sportheime', $guard(fn(Request $r, array $p) => $c->sportheimController()->index($r)));
    $router->get('/admin/sportheime/neu', $guard(fn(Request $r, array $p) => $c->sportheimController()->createForm($r)));
    $router->post('/admin/sportheime', $guard(fn(Request $r, array $p) => $c->sportheimController()->create($r)));
    $router->post('/admin/sportheime/sortierung', $guard(fn(Request $r, array $p) => $c->sportheimController()->sortierung($r)));
    $router->get('/admin/sportheime/{id:\d+}', $guard(fn(Request $r, array $p) => $c->sportheimController()->editForm($r, $p)));
    $router->post('/admin/sportheime/{id:\d+}', $guard(fn(Request $r, array $p) => $c->sportheimController()->update($r, $p)));
    $router->post('/admin/sportheime/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->sportheimController()->delete($r, $p)));
    $router->post('/admin/sportheime/{id:\d+}/raeume', $guard(fn(Request $r, array $p) => $c->sportheimController()->addRaum($r, $p)));
    $router->post('/admin/sportheime/raeume/sortierung', $guard(fn(Request $r, array $p) => $c->sportheimController()->raumSortierung($r)));
    $router->post('/admin/raeume/{id:\d+}/loeschen', $guard(fn(Request $r, array $p) => $c->sportheimController()->deleteRaum($r, $p)));

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
    $router->post('/admin/saison/heimplaetze-kopieren', $guard(fn(Request $r, array $p) => $c->saisonController()->copyHomePitchRules($r)));

    $router->get('/admin/seiten/{key:impressum|datenschutz}', $guard(fn(Request $r, array $p) => $c->pageAdminController()->form($r, $p)));
    $router->post('/admin/seiten/{key:impressum|datenschutz}', $guard(fn(Request $r, array $p) => $c->pageAdminController()->save($r, $p)));

    $router->get('/admin/einstellungen', $guard(fn(Request $r, array $p) => $c->settingsController()->form($r)));
    $router->post('/admin/einstellungen', $guard(fn(Request $r, array $p) => $c->settingsController()->save($r)));
    $router->post('/admin/einstellungen/wappen', $guard(fn(Request $r, array $p) => $c->settingsController()->uploadWappen($r)));
};
