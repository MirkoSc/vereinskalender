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
            return $this->errorResponse($e);
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

    private function errorResponse(\Throwable $e): Response
    {
        error_log((string) $e);

        $body = $this->debug
            ? (string) $e
            : 'Interner Fehler – bitte versuchen Sie es später erneut.';

        return new Response(500, ['Content-Type' => 'text/plain; charset=utf-8'], $body);
    }
}
