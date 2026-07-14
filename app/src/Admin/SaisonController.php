<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\ImportSourceRepository;
use App\Repository\TeamRepository;
use App\Service\Saison\SaisonService;
use App\View\View;

final class SaisonController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly SaisonService $saison,
        private readonly TeamRepository $teams,
        private readonly ImportSourceRepository $sources,
    ) {
        parent::__construct($view, $session);
    }

    public function page(Request $request): ResponseInterface
    {
        return $this->render('admin/saison', [
            'title' => 'Saison-Assistent',
            'teams' => $this->teams->findAll(),
            'sources' => $this->sources->findAll(),
            'slots' => $this->saison->copyCandidates(),
        ]);
    }

    public function copySlots(Request $request): ResponseInterface
    {
        $slotIds = array_values(array_filter(array_map(intval(...), (array) ($request->post['slot_ids'] ?? []))));
        $gueltigAb = trim((string) ($request->post['gueltig_ab'] ?? ''));
        $gueltigBis = trim((string) ($request->post['gueltig_bis'] ?? ''));

        if ($slotIds === []) {
            $this->session->flash('Bitte mindestens einen Trainingsslot auswählen.');

            return Response::redirect('/admin/saison');
        }

        $result = $this->saison->copySlots($slotIds, $gueltigAb, $gueltigBis, $this->context($request));

        $meldung = sprintf('%d Trainingsslot(s) für die neue Saison angelegt.', $result['angelegt']);
        if ($result['fehler'] !== []) {
            $meldung .= ' Probleme: ' . implode(' ', $result['fehler']);
        }
        $this->session->flash($meldung);

        return Response::redirect('/admin/saison');
    }
}
