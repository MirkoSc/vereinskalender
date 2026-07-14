<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\PageRepository;
use App\View\View;

final class PageAdminController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly PageRepository $pages,
    ) {
        parent::__construct($view, $session);
    }

    /**
     * @param array<string, string> $params
     */
    public function form(Request $request, array $params): ResponseInterface
    {
        $page = $this->pages->find($params['key']);
        if ($page === null) {
            return Response::redirect('/admin');
        }

        return $this->render('admin/seite_form', [
            'title' => 'Seite bearbeiten: ' . $page['titel'],
            'page' => $page,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): ResponseInterface
    {
        $page = $this->pages->find($params['key']);
        if ($page === null) {
            return Response::redirect('/admin');
        }

        $titel = trim((string) ($request->post['titel'] ?? ''));
        $inhalt = (string) ($request->post['inhalt'] ?? '');
        if ($titel === '' || mb_strlen($titel) > 100) {
            $this->session->flash('Titel ist erforderlich (max. 100 Zeichen).');
        } else {
            $this->pages->update($page['key'], $titel, $inhalt);
            $this->session->flash('Seite gespeichert.');
        }

        return Response::redirect('/admin/seiten/' . $page['key']);
    }
}
