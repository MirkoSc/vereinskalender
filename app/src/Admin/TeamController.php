<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\PitchRepository;
use App\Repository\TeamHomePitchRepository;
use App\Repository\TeamRepository;
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
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/teams', [
            'title' => 'Teams',
            'teams' => $this->teams->findAll(),
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
            return $this->render('admin/team_form', [
                'title' => 'Team bearbeiten',
                'action' => '/admin/teams/' . $id,
                'values' => $request->post,
                'errors' => $e->getErrors(),
                'homePitchRules' => $this->homePitchRules->findByTeamWithPitchNames($id),
                'pitches' => $this->pitches->findAll(),
                'teamId' => $id,
            ], 422);
        }

        $this->session->flash('Team gespeichert.');

        return Response::redirect('/admin/teams');
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
}
