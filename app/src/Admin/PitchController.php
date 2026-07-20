<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Domain\AggregateType;
use App\Repository\PitchRepository;
use App\Repository\SportheimRepository;
use App\Repository\VenueRepository;
use App\Service\Stammdaten\PitchService;
use App\Service\Stammdaten\SortierungService;
use App\Service\ValidationException;
use App\View\View;

final class PitchController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly PitchRepository $pitches,
        private readonly VenueRepository $venues,
        private readonly SportheimRepository $sportheime,
        private readonly PitchService $service,
        private readonly SortierungService $sortierung,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/pitches', [
            'title' => 'Plätze',
            'pitches' => $this->pitches->findAll(),
        ]);
    }

    public function createForm(Request $request): ResponseInterface
    {
        return $this->render('admin/pitch_form', [
            'title' => 'Platz anlegen',
            'action' => '/admin/plaetze',
            'venues' => $this->venues->findAll(),
            'sportheime' => $this->sportheime->findAktive(),
            'values' => [],
            'errors' => [],
        ]);
    }

    public function create(Request $request): ResponseInterface
    {
        try {
            $this->service->create($request->post, $this->context($request));
        } catch (ValidationException $e) {
            return $this->render('admin/pitch_form', [
                'title' => 'Platz anlegen',
                'action' => '/admin/plaetze',
                'venues' => $this->venues->findAll(),
                'sportheime' => $this->sportheime->findAktive(),
                'values' => $request->post,
                'errors' => $e->getErrors(),
            ], 422);
        }

        $this->session->flash('Platz angelegt.');

        return Response::redirect('/admin/plaetze');
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];
        $pitch = $this->pitches->find($id);
        if ($pitch === null) {
            return Response::redirect('/admin/plaetze');
        }

        return $this->render('admin/pitch_form', [
            'title' => 'Platz bearbeiten',
            'action' => '/admin/plaetze/' . $id,
            'venues' => $this->venues->findAll(),
            'sportheime' => $this->sportheime->findAktive(),
            'values' => [...$pitch, 'flutlicht' => ((int) $pitch['flutlicht'] === 1) ? '1' : ''],
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
            return $this->render('admin/pitch_form', [
                'title' => 'Platz bearbeiten',
                'action' => '/admin/plaetze/' . $id,
                'venues' => $this->venues->findAll(),
                'sportheime' => $this->sportheime->findAktive(),
                'values' => $request->post,
                'errors' => $e->getErrors(),
            ], 422);
        }

        $this->session->flash('Platz gespeichert.');

        return Response::redirect('/admin/plaetze');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): ResponseInterface
    {
        try {
            $this->service->delete((int) $params['id'], $this->context($request));
            $this->session->flash('Platz gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/plaetze');
    }

    public function sortierung(Request $request): ResponseInterface
    {
        $ids = array_map(intval(...), (array) ($request->post['ids'] ?? []));

        try {
            $this->sortierung->reorder(AggregateType::Pitch, $ids, $this->context($request));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }

        return Response::json(['ok' => true]);
    }
}
