<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AggregateType;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\SportheimRaumRepository;
use App\Repository\SportheimRepository;
use App\Repository\VenueRepository;
use App\Service\Stammdaten\SortierungService;
use App\Service\Stammdaten\SportheimService;
use App\Service\ValidationException;
use App\View\View;

final class SportheimController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly SportheimRepository $sportheime,
        private readonly SportheimRaumRepository $raeume,
        private readonly VenueRepository $venues,
        private readonly SportheimService $service,
        private readonly SortierungService $sortierung,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/sportheime', [
            'title' => 'Sportheime',
            'sportheime' => $this->sportheime->findAll(),
        ]);
    }

    public function createForm(Request $request): ResponseInterface
    {
        return $this->render('admin/sportheim_form', [
            'title' => 'Sportheim anlegen',
            'action' => '/admin/sportheime',
            'venues' => $this->venues->findAll(),
            'values' => ['aktiv' => '1'],
            'errors' => [],
            'raeume' => null,
        ]);
    }

    public function create(Request $request): ResponseInterface
    {
        try {
            $id = $this->service->create($request->post, $this->context($request));
        } catch (ValidationException $e) {
            return $this->render('admin/sportheim_form', [
                'title' => 'Sportheim anlegen',
                'action' => '/admin/sportheime',
                'venues' => $this->venues->findAll(),
                'values' => $request->post,
                'errors' => $e->getErrors(),
                'raeume' => null,
            ], 422);
        }

        $this->session->flash('Sportheim angelegt. Bitte Räume ergänzen.');

        return Response::redirect('/admin/sportheime/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];
        $sportheim = $this->sportheime->find($id);
        if ($sportheim === null) {
            return Response::redirect('/admin/sportheime');
        }

        return $this->render('admin/sportheim_form', [
            'title' => 'Sportheim bearbeiten',
            'action' => '/admin/sportheime/' . $id,
            'venues' => $this->venues->findAll(),
            'values' => [...$sportheim, 'aktiv' => ((int) $sportheim['aktiv'] === 1) ? '1' : ''],
            'errors' => [],
            'raeume' => $this->raeume->findBySportheim($id),
            'sportheimId' => $id,
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
            return $this->render('admin/sportheim_form', [
                'title' => 'Sportheim bearbeiten',
                'action' => '/admin/sportheime/' . $id,
                'venues' => $this->venues->findAll(),
                'values' => $request->post,
                'errors' => $e->getErrors(),
                'raeume' => $this->raeume->findBySportheim($id),
                'sportheimId' => $id,
            ], 422);
        }

        $this->session->flash('Sportheim gespeichert.');

        return Response::redirect('/admin/sportheime');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): ResponseInterface
    {
        try {
            $this->service->delete((int) $params['id'], $this->context($request));
            $this->session->flash('Sportheim gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/sportheime');
    }

    /**
     * @param array<string, string> $params
     */
    public function addRaum(Request $request, array $params): ResponseInterface
    {
        $sportheimId = (int) $params['id'];

        try {
            $this->service->addRaum($sportheimId, $request->post, $this->context($request));
            $this->session->flash('Raum hinzugefügt.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/sportheime/' . $sportheimId);
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteRaum(Request $request, array $params): ResponseInterface
    {
        $raum = $this->raeume->find((int) $params['id']);
        $backTo = $raum !== null ? '/admin/sportheime/' . (int) $raum['sportheim_id'] : '/admin/sportheime';

        try {
            $this->service->deleteRaum((int) $params['id'], $this->context($request));
            $this->session->flash('Raum gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect($backTo);
    }

    public function sortierung(Request $request): ResponseInterface
    {
        $ids = array_map(intval(...), (array) ($request->post['ids'] ?? []));

        try {
            $this->sortierung->reorder(AggregateType::Sportheim, $ids, $this->context($request));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }

        return Response::json(['ok' => true]);
    }

    public function raumSortierung(Request $request): ResponseInterface
    {
        $ids = array_map(intval(...), (array) ($request->post['ids'] ?? []));

        try {
            $this->sortierung->reorder(AggregateType::SportheimRaum, $ids, $this->context($request));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }

        return Response::json(['ok' => true]);
    }
}
