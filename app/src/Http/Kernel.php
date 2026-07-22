<?php

declare(strict_types=1);

namespace App\Http;

use App\View\View;

/**
 * Request -> Response pipeline: static assets first, then routing.
 */
final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly StaticFileHandler $staticFiles,
        private readonly View $view,
        private readonly bool $debug = false,
    ) {
    }

    public function handle(Request $request): ResponseInterface
    {
        try {
            return $this->staticFiles->tryServe($request) ?? $this->dispatch($request);
        } catch (\Throwable $e) {
            return $this->errorResponse($e, $request);
        }
    }

    private function dispatch(Request $request): ResponseInterface
    {
        $match = $this->router->match($request->method, $request->path);

        return match ($match->type) {
            MatchType::Matched => ($match->handler)($request, $match->params),
            MatchType::NotFound => Response::html(
                $this->view->render('error', [
                    'title' => 'Seite nicht gefunden',
                    'message' => 'Die angeforderte Seite existiert nicht.',
                ]),
                404,
            ),
            MatchType::MethodNotAllowed => new Response(
                405,
                [
                    'Allow' => implode(', ', array_map(
                        static fn(HttpMethod $m): string => $m->value,
                        $match->allowedMethods,
                    )),
                    'Content-Type' => 'text/plain; charset=utf-8',
                ],
                'Methode nicht erlaubt',
            ),
        };
    }

    private function errorResponse(\Throwable $e, Request $request): Response
    {
        // Structured log WITHOUT the full exception string: a stack trace can
        // carry function arguments (e.g. the plaintext password from the login
        // path) when zend.exception_ignore_args is off. Logging class, message
        // and request context only keeps the guarantee "Passwörter nie loggen"
        // (CLAUDE.md section 5) independent of that ini setting - defense in
        // depth, and a shorter, more useful log line (K2). PDO exception
        // messages never contain bound values, so getMessage() is safe here.
        error_log(sprintf(
            '%s: %s [%s %s] at %s:%d',
            $e::class,
            $e->getMessage(),
            $request->method->value,
            $request->path,
            $e->getFile(),
            $e->getLine(),
        ));

        // Full trace only in debug mode (dev/install), never in production.
        $body = $this->debug
            ? (string) $e
            : 'Interner Fehler – bitte versuchen Sie es später erneut.';

        return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }
}
