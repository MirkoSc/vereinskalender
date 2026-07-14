<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\FileResponse;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Service\Backup\BackupService;
use App\View\View;

final class BackupController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BackupService $backups,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/backups', [
            'title' => 'Backups',
            'backups' => $this->backups->list(),
        ]);
    }

    public function create(Request $request): ResponseInterface
    {
        try {
            $name = $this->backups->create();
            $this->session->flash('Backup erstellt: ' . $name);
        } catch (\Throwable $e) {
            $this->session->flash('Backup fehlgeschlagen: ' . $e->getMessage());
        }

        return Response::redirect('/admin/backups');
    }

    /**
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): ResponseInterface
    {
        $path = $this->backups->path($params['name']);
        if ($path === null) {
            return Response::redirect('/admin/backups');
        }

        return new FileResponse($path, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
        ]);
    }
}
