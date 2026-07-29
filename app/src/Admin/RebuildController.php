<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\EventStore\RebuildService;
use App\View\View;

/**
 * Rebuild as a step chain: the page's JS calls start once, then step
 * repeatedly until done (each request stays far below the PHP time limit).
 */
final class RebuildController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly RebuildService $rebuild,
    ) {
        parent::__construct($view, $session);
    }

    public function page(Request $request): ResponseInterface
    {
        return $this->render('admin/rebuild', [
            'title' => 'Projektionen neu aufbauen',
            'state' => $this->rebuild->state(),
        ]);
    }

    public function start(Request $request): ResponseInterface
    {
        return Response::json($this->rebuild->start()->toArray());
    }

    public function step(Request $request): ResponseInterface
    {
        return Response::json($this->rebuild->step()->toArray());
    }

    /**
     * Abort: drops the shadow tables and lifts the write freeze. A plain
     * form POST rather than part of the JS chain, because the case it exists
     * for is precisely the one where the JS is gone (tab closed mid-rebuild).
     */
    public function cancel(Request $request): ResponseInterface
    {
        $this->rebuild->cancel();
        $this->session->flash('Rebuild abgebrochen, Wartungsmodus aufgehoben. Die Projektionen sind unverändert.');

        return Response::redirect('/admin/rebuild');
    }
}
