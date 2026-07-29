<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Update\UpdateService;
use App\View\View;

/**
 * Admin UI for the update step chain: the page's JS calls one endpoint per
 * step; on errors it offers retry (steps are idempotent) or rollback.
 */
final class UpdateController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly UpdateService $updates,
    ) {
        parent::__construct($view, $session);
    }

    public function page(Request $request): ResponseInterface
    {
        return $this->render('admin/update', [
            'title' => 'Update',
            'state' => $this->updates->state(),
            'kanal' => $this->updates->channel(),
        ]);
    }

    public function setChannel(Request $request): ResponseInterface
    {
        $this->updates->setChannel((string) ($request->post['kanal'] ?? 'stable'));
        $this->updates->reset();
        $this->session->flash('Update-Kanal gespeichert.');

        return Response::redirect('/admin/update');
    }

    /**
     * @param array<string, string> $params
     */
    public function step(Request $request, array $params): ResponseInterface
    {
        try {
            $state = match ($params['schritt']) {
                'check' => $this->updates->check(),
                'backup' => $this->updates->backup(),
                'download' => $this->updates->download(),
                'extract' => $this->updates->extract(),
                'switch' => $this->updates->switchRelease(),
                'migrate' => $this->updates->migrate(),
                'finish' => $this->updates->finish($this->baseUrl($request)),
                'rollback' => $this->updates->rollback(),
                default => null,
            };
        } catch (\Throwable $e) {
            return Response::json(['fehler' => $e->getMessage()], 500);
        }

        if ($state === null) {
            return Response::json(['fehler' => 'Unbekannter Schritt.'], 404);
        }

        return Response::json($state->toArray(), $state->fehler !== null ? 500 : 200);
    }

    public function resetState(Request $request): ResponseInterface
    {
        $this->updates->reset();
        $this->session->flash('Update-Status zurückgesetzt.');

        return Response::redirect('/admin/update');
    }

    private function baseUrl(Request $request): string
    {
        $host = $request->header('host') ?? 'localhost';

        return (Request::httpsFromGlobals() ? 'https://' : 'http://') . $host;
    }
}
