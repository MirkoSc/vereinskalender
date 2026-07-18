<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Domain\AggregateType;
use App\Repository\PitchRepository;
use App\Repository\VenueRepository;
use App\Service\Stammdaten\SortierungService;
use App\Service\Stammdaten\VenueService;
use App\Service\ValidationException;
use App\View\View;

final class VenueController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly VenueRepository $venues,
        private readonly PitchRepository $pitches,
        private readonly VenueService $service,
        private readonly SortierungService $sortierung,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/venues', [
            'title' => 'Spielstätten',
            'venues' => $this->venues->findAll(),
        ]);
    }

    public function createForm(Request $request): ResponseInterface
    {
        return $this->render('admin/venue_form', [
            'title' => 'Spielstätte anlegen',
            'action' => '/admin/spielstaetten',
            'values' => [],
            'errors' => [],
            'venuePitches' => [],
            'begriffe' => null,
        ]);
    }

    public function create(Request $request): ResponseInterface
    {
        try {
            $id = $this->service->create($request->post, $this->context($request));
        } catch (ValidationException $e) {
            return $this->render('admin/venue_form', [
                'title' => 'Spielstätte anlegen',
                'action' => '/admin/spielstaetten',
                'values' => $request->post,
                'errors' => $e->getErrors(),
                'venuePitches' => [],
                'begriffe' => null,
            ], 422);
        }

        $this->session->flash('Spielstätte angelegt. Bitte Begriffe für die Ortserkennung ergänzen.');

        return Response::redirect('/admin/spielstaetten/' . $id);
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];
        $venue = $this->venues->find($id);
        if ($venue === null) {
            return Response::redirect('/admin/spielstaetten');
        }

        return $this->render('admin/venue_form', [
            'title' => 'Spielstätte bearbeiten',
            'action' => '/admin/spielstaetten/' . $id,
            'values' => $venue,
            'errors' => [],
            'venuePitches' => $this->pitchesOfVenue($id),
            'begriffe' => $this->venues->findBegriffe($id),
            'venueId' => $id,
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
            return $this->render('admin/venue_form', [
                'title' => 'Spielstätte bearbeiten',
                'action' => '/admin/spielstaetten/' . $id,
                'values' => $request->post,
                'errors' => $e->getErrors(),
                'venuePitches' => $this->pitchesOfVenue($id),
                'begriffe' => $this->venues->findBegriffe($id),
                'venueId' => $id,
            ], 422);
        }

        $this->session->flash('Spielstätte gespeichert.');

        return Response::redirect('/admin/spielstaetten');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): ResponseInterface
    {
        try {
            $this->service->delete((int) $params['id'], $this->context($request));
            $this->session->flash('Spielstätte gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/spielstaetten');
    }

    /**
     * @param array<string, string> $params
     */
    public function addBegriff(Request $request, array $params): ResponseInterface
    {
        $venueId = (int) $params['id'];

        try {
            $this->service->addBegriff($venueId, $request->post, $this->context($request));
            $this->session->flash('Begriff hinzugefügt.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/spielstaetten/' . $venueId);
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteBegriff(Request $request, array $params): ResponseInterface
    {
        $begriff = $this->venues->findBegriff((int) $params['id']);
        $backTo = $begriff !== null ? '/admin/spielstaetten/' . (int) $begriff['venue_id'] : '/admin/spielstaetten';

        try {
            $this->service->deleteBegriff((int) $params['id'], $this->context($request));
            $this->session->flash('Begriff gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect($backTo);
    }

    public function sortierung(Request $request): ResponseInterface
    {
        $ids = array_map(intval(...), (array) ($request->post['ids'] ?? []));

        try {
            $this->sortierung->reorder(AggregateType::Venue, $ids, $this->context($request));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }

        return Response::json(['ok' => true]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pitchesOfVenue(int $venueId): array
    {
        return array_values(array_filter(
            $this->pitches->findAll(),
            static fn(array $pitch): bool => (int) $pitch['venue_id'] === $venueId,
        ));
    }
}
