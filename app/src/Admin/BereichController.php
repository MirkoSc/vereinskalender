<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AggregateType;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\BereichRepository;
use App\Service\Stammdaten\BereichService;
use App\Service\Stammdaten\SortierungService;
use App\Service\ValidationException;
use App\View\View;

final class BereichController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly BereichRepository $bereiche,
        private readonly BereichService $service,
        private readonly SortierungService $sortierung,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/bereiche', [
            'title' => 'Bereiche',
            'bereiche' => $this->bereiche->findAll(),
        ]);
    }

    public function createForm(Request $request): ResponseInterface
    {
        return $this->render('admin/bereich_form', [
            'title' => 'Bereich anlegen',
            'action' => '/admin/bereiche',
            'values' => ['aktiv' => '1'],
            'errors' => [],
        ]);
    }

    public function create(Request $request): ResponseInterface
    {
        try {
            $this->service->create($request->post, $this->context($request));
        } catch (ValidationException $e) {
            return $this->render('admin/bereich_form', [
                'title' => 'Bereich anlegen',
                'action' => '/admin/bereiche',
                'values' => $request->post,
                'errors' => $e->getErrors(),
            ], 422);
        }

        $this->session->flash('Bereich angelegt.');

        return Response::redirect('/admin/bereiche');
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];
        $bereich = $this->bereiche->find($id);
        if ($bereich === null) {
            return Response::redirect('/admin/bereiche');
        }

        return $this->render('admin/bereich_form', [
            'title' => 'Bereich bearbeiten',
            'action' => '/admin/bereiche/' . $id,
            'values' => [...$bereich, 'aktiv' => ((int) $bereich['aktiv'] === 1) ? '1' : ''],
            'errors' => [],
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];

        try {
            $this->service->update($id, $request->post, $this->context($request));
        } catch (ValidationException $e) {
            return $this->render('admin/bereich_form', [
                'title' => 'Bereich bearbeiten',
                'action' => '/admin/bereiche/' . $id,
                'values' => $request->post,
                'errors' => $e->getErrors(),
            ], 422);
        }

        $this->session->flash('Bereich gespeichert.');

        return Response::redirect('/admin/bereiche');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): ResponseInterface
    {
        try {
            $this->service->delete((int) $params['id'], $this->context($request));
            $this->session->flash('Bereich gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/bereiche');
    }

    public function sortierung(Request $request): ResponseInterface
    {
        $ids = array_map(intval(...), (array) ($request->post['ids'] ?? []));

        try {
            $this->sortierung->reorder(AggregateType::Bereich, $ids, $this->context($request));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }

        return Response::json(['ok' => true]);
    }
}
