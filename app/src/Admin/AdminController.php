<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\View\View;

abstract class AdminController
{
    public function __construct(
        protected readonly View $view,
        protected readonly Session $session,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html(
            $this->view->render($template, [
                'csrf' => $this->session->csrfToken(),
                'flash' => $this->session->pullFlash(),
                'adminUsername' => $this->session->adminUsername(),
                'showNav' => $this->session->isAdmin(),
                ...$data,
            ], 'admin/layout'),
            $status,
        );
    }

    protected function context(Request $request): EventContext
    {
        return new EventContext(
            $this->session->adminUsername() ?? 'bootstrap',
            $request->ip,
            EventSource::Admin,
        );
    }
}
