<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\AggregateType;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\BereichRepository;
use App\Repository\PitchRepository;
use App\Repository\TeamHomePitchRepository;
use App\Repository\TeamRepository;
use App\Service\Stammdaten\SortierungService;
use App\Service\Stammdaten\TeamHomePitchService;
use App\Service\Stammdaten\TeamService;
use App\Service\ValidationException;
use App\View\View;

final class TeamController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TeamRepository $teams,
        private readonly TeamService $service,
        private readonly TeamHomePitchRepository $homePitchRules,
        private readonly TeamHomePitchService $homePitchService,
        private readonly PitchRepository $pitches,
        private readonly BereichRepository $bereiche,
        private readonly SortierungService $sortierung,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/teams', [
            'title' => 'Teams',
            'teams' => $this->teams->findAll(),
            'bereiche' => $this->bereicheById(),
        ]);
    }

    public function createForm(Request $request): ResponseInterface
    {
        return $this->render('admin/team_form', [
            'title' => 'Team anlegen',
            'action' => '/admin/teams',
            'values' => ['aktiv' => '1'],
            'errors' => [],
            'homePitchRules' => null,
            'pitches' => [],
            'bereiche' => $this->bereiche->findAktive(),
        ]);
    }

    public function create(Request $request): ResponseInterface
    {
        try {
            $this->service->create($request->post, $this->context($request));
        } catch (ValidationException $e) {
            return $this->render('admin/team_form', [
                'title' => 'Team anlegen',
                'action' => '/admin/teams',
                'values' => $request->post,
                'errors' => $e->getErrors(),
                'homePitchRules' => null,
                'pitches' => [],
                'bereiche' => $this->bereiche->findAktive(),
            ], 422);
        }

        $this->session->flash('Team angelegt.');

        return Response::redirect('/admin/teams');
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];
        $team = $this->teams->find($id);
        if ($team === null) {
            return Response::redirect('/admin/teams');
        }

        return $this->render('admin/team_form', [
            'title' => 'Team bearbeiten',
            'action' => '/admin/teams/' . $id,
            'values' => [...$team, 'aktiv' => ((int) $team['aktiv'] === 1) ? '1' : ''],
            'errors' => [],
            'homePitchRules' => $this->homePitchRules->findByTeamWithPitchNames($id),
            'pitches' => $this->pitches->findAll(),
            'bereiche' => $this->bereicheForForm($team),
            'teamId' => $id,
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
            $team = $this->teams->find($id);

            return $this->render('admin/team_form', [
                'title' => 'Team bearbeiten',
                'action' => '/admin/teams/' . $id,
                'values' => $request->post,
                'errors' => $e->getErrors(),
                'homePitchRules' => $this->homePitchRules->findByTeamWithPitchNames($id),
                'pitches' => $this->pitches->findAll(),
                'bereiche' => $team !== null ? $this->bereicheForForm($team) : $this->bereiche->findAktive(),
                'teamId' => $id,
            ], 422);
        }

        $this->session->flash('Team gespeichert.');

        return Response::redirect('/admin/teams');
    }

    public function sortierung(Request $request): ResponseInterface
    {
        $ids = array_map(intval(...), (array) ($request->post['ids'] ?? []));

        try {
            $this->sortierung->reorder(AggregateType::Team, $ids, $this->context($request));
        } catch (ValidationException $e) {
            return Response::json(['fehler' => $e->getErrors()], 422);
        }

        return Response::json(['ok' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): ResponseInterface
    {
        try {
            $this->service->delete((int) $params['id'], $this->context($request));
            $this->session->flash('Team gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/teams');
    }

    /**
     * @param array<string, string> $params
     */
    public function addHomePitch(Request $request, array $params): ResponseInterface
    {
        $teamId = (int) $params['id'];

        try {
            $this->homePitchService->create([...$request->post, 'team_id' => $teamId], $this->context($request));
            $this->session->flash('Heimspielstätten-Regel angelegt.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/teams/' . $teamId);
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteHomePitch(Request $request, array $params): ResponseInterface
    {
        $rule = $this->homePitchRules->find((int) $params['id']);
        $backTo = $rule !== null ? '/admin/teams/' . (int) $rule['team_id'] : '/admin/teams';

        try {
            $this->homePitchService->delete((int) $params['id'], $this->context($request));
            $this->session->flash('Heimspielstätten-Regel gelöscht.');
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect($backTo);
    }

    /**
     * @return array<int, array<string, mixed>> bereich rows keyed by id, for
     *         resolving the display name in admin/teams.php
     */
    private function bereicheById(): array
    {
        $byId = [];
        foreach ($this->bereiche->findAll() as $bereich) {
            $byId[(int) $bereich['id']] = $bereich;
        }

        return $byId;
    }

    /**
     * Active bereiche plus the team's current one even if it was since
     * deactivated (so the select still shows/keeps it, CLAUDE.md section 3).
     *
     * @param array<string, mixed> $team
     * @return list<array<string, mixed>>
     */
    private function bereicheForForm(array $team): array
    {
        $bereiche = $this->bereiche->findAktive();
        $currentId = $team['bereich_id'] !== null ? (int) $team['bereich_id'] : null;
        $aktiveIds = array_map(intval(...), array_column($bereiche, 'id'));
        if ($currentId !== null && !in_array($currentId, $aktiveIds, true)) {
            $current = $this->bereiche->find($currentId);
            if ($current !== null) {
                $bereiche[] = $current;
            }
        }

        return $bereiche;
    }
}
