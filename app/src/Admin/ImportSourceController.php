<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\ImportSourceRepository;
use App\Repository\MatchRepository;
use App\Repository\TeamRepository;
use App\Service\Import\IcsImportService;
use App\Service\Import\ImportSourceService;
use App\Service\ValidationException;
use App\View\View;

final class ImportSourceController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly ImportSourceRepository $sources,
        private readonly TeamRepository $teams,
        private readonly MatchRepository $matches,
        private readonly ImportSourceService $service,
        private readonly IcsImportService $import,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        $teamNames = [];
        foreach ($this->teams->findAll() as $team) {
            $teamNames[(int) $team['id']] = (string) $team['name'];
        }

        $verwaiste = array_map(
            static fn(array $match): array => [
                ...$match,
                'team_name' => $teamNames[(int) $match['team_id']] ?? ('Team #' . $match['team_id']),
            ],
            $this->matches->findOrphanedImports(),
        );

        return $this->render('admin/import_sources', [
            'title' => 'Import-Quellen',
            'sources' => $this->sources->findAll(),
            'verwaiste' => $verwaiste,
        ]);
    }

    public function createForm(Request $request): ResponseInterface
    {
        return $this->render('admin/import_source_form', [
            'title' => 'Import-Quelle anlegen',
            'action' => '/admin/import-quellen',
            'teams' => $this->teams->findAll(),
            'values' => ['aktiv' => '1'],
            'errors' => [],
        ]);
    }

    public function create(Request $request): ResponseInterface
    {
        try {
            $this->service->create($request->post, $this->context($request));
        } catch (ValidationException $e) {
            return $this->render('admin/import_source_form', [
                'title' => 'Import-Quelle anlegen',
                'action' => '/admin/import-quellen',
                'teams' => $this->teams->findAll(),
                'values' => $request->post,
                'errors' => $e->getErrors(),
            ], 422);
        }

        $this->session->flash('Import-Quelle angelegt.');

        return Response::redirect('/admin/import-quellen');
    }

    /**
     * @param array<string, string> $params
     */
    public function editForm(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];
        $source = $this->sources->find($id);
        if ($source === null) {
            return Response::redirect('/admin/import-quellen');
        }

        return $this->render('admin/import_source_form', [
            'title' => 'Import-Quelle bearbeiten',
            'action' => '/admin/import-quellen/' . $id,
            'teams' => $this->teams->findAll(),
            'values' => [...$source, 'aktiv' => ((int) $source['aktiv'] === 1) ? '1' : ''],
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
            return $this->render('admin/import_source_form', [
                'title' => 'Import-Quelle bearbeiten',
                'action' => '/admin/import-quellen/' . $id,
                'teams' => $this->teams->findAll(),
                'values' => $request->post,
                'errors' => $e->getErrors(),
            ], 422);
        }

        $this->session->flash('Import-Quelle gespeichert.');

        return Response::redirect('/admin/import-quellen');
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): ResponseInterface
    {
        try {
            $orphaned = $this->service->delete((int) $params['id'], $this->context($request));
            $this->session->flash($orphaned === 0
                ? 'Import-Quelle gelöscht.'
                : sprintf(
                    'Import-Quelle gelöscht. %d zugehörige Spiele bleiben bestehen und lassen sich unten als verwaiste Import-Spiele löschen.',
                    $orphaned,
                ));
        } catch (ValidationException $e) {
            $this->session->flash(implode(' ', $e->getErrors()));
        }

        return Response::redirect('/admin/import-quellen');
    }

    /**
     * Manual trigger for testing; the regular run comes from the cron
     * endpoint every 10 minutes.
     */
    public function run(Request $request): ResponseInterface
    {
        $results = $this->import->runAll();

        if ($results === []) {
            $this->session->flash('Keine aktiven Import-Quellen vorhanden.');
        } else {
            $parts = [];
            foreach ($results as $result) {
                $parts[] = $result->ok
                    ? sprintf(
                        'Quelle #%d: %d neu, %d aktualisiert, %d abgesagt, %d gelöscht, %d unverändert.',
                        $result->sourceId,
                        $result->inserted,
                        $result->updated,
                        $result->cancelled,
                        $result->deleted,
                        $result->skipped,
                    )
                    : sprintf('Quelle #%d: FEHLER – %s', $result->sourceId, (string) $result->fehlertext);
            }
            $this->session->flash('Import ausgeführt. ' . implode(' ', $parts));
        }

        return Response::redirect('/admin/import-quellen');
    }

    /**
     * Deletes this source's own future matches and re-fetches the feed
     * immediately (IcsImportService::resetSource(), CLAUDE.md section 6) -
     * the admin escape hatch for a source whose past runs left duplicate or
     * stuck matches behind. Runs even for an inactive source: a click on
     * this specific row overrides the aktiv flag, which only gates the
     * cron's own findActive() loop.
     *
     * @param array<string, string> $params
     */
    public function reset(Request $request, array $params): ResponseInterface
    {
        $source = $this->sources->find((int) $params['id']);
        if ($source === null) {
            return Response::redirect('/admin/import-quellen');
        }

        $result = $this->import->resetSource($source, $this->context($request));

        $this->session->flash($result->ok
            ? sprintf(
                'Quelle #%d zurückgesetzt: %d entfernt, %d neu, %d aktualisiert, %d unverändert.',
                $result->sourceId,
                $result->purged,
                $result->inserted,
                $result->updated,
                $result->skipped,
            )
            : sprintf('Quelle #%d: FEHLER – %s', $result->sourceId, (string) $result->fehlertext));

        return Response::redirect('/admin/import-quellen');
    }

    /**
     * Removes every match whose import_source_id points at a since-deleted
     * source (ImportSourceService::deleteOrphanedMatches()) - the cleanup
     * for the duplicates a deleted-and-recreated source leaves behind,
     * which resetSource() cannot reach because it only ever sees matches of
     * the ONE source clicked.
     */
    public function deleteOrphans(Request $request): ResponseInterface
    {
        $deleted = $this->service->deleteOrphanedMatches($this->context($request));

        $this->session->flash($deleted === 0
            ? 'Keine verwaisten Import-Spiele vorhanden.'
            : sprintf('%d verwaiste Import-Spiele gelöscht.', $deleted));

        return Response::redirect('/admin/import-quellen');
    }
}
