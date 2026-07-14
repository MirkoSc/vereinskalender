<?php

declare(strict_types=1);

namespace App\Admin;

use App\Domain\EventContext;
use App\Domain\EventSource;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\EventHistoryRepository;
use App\Service\EventStore\EventStore;
use App\View\View;

/**
 * Admin event history (CLAUDE.md section 5): filters, detail view with
 * payload, single/mass exclusion, correction editor, undo, rebuild link.
 */
final class EventHistoryController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly EventHistoryRepository $history,
        private readonly EventStore $eventStore,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        $filters = array_map(
            static fn(mixed $v): string => trim((string) $v),
            array_intersect_key($request->query, array_flip([
                'ip', 'editor', 'aggregat_typ', 'event_typ', 'quelle', 'von', 'bis', 'nur_ausgeschlossen',
            ])),
        );
        $page = max(1, (int) ($request->query['seite'] ?? 1));
        $result = $this->history->search($filters, $page);

        return $this->render('admin/events', [
            'title' => 'Event-Historie',
            'events' => $result['events'],
            'gesamt' => $result['gesamt'],
            'seiten' => $result['seiten'],
            'seite' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function detail(Request $request, array $params): ResponseInterface
    {
        $event = $this->eventStore->find((int) $params['id']);
        if ($event === null) {
            return Response::redirect('/admin/events');
        }

        return $this->render('admin/event_detail', [
            'title' => 'Event #' . $event->id,
            'event' => $event,
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function exclude(Request $request, array $params): ResponseInterface
    {
        $grund = trim((string) ($request->post['grund'] ?? ''));
        if ($grund === '') {
            $this->session->flash('Bitte einen Grund für den Ausschluss angeben.');
        } else {
            $this->eventStore->exclude((int) $params['id'], $this->session->adminUsername() ?? 'admin', $grund);
            $this->session->flash('Event ausgeschlossen. Wirksam wird das erst mit dem nächsten Rebuild.');
        }

        return Response::redirect('/admin/events/' . (int) $params['id']);
    }

    /**
     * @param array<string, string> $params
     */
    public function undoExclude(Request $request, array $params): ResponseInterface
    {
        $this->eventStore->undoExclude((int) $params['id']);
        $this->session->flash('Ausschluss aufgehoben. Wirksam wird das erst mit dem nächsten Rebuild.');

        return Response::redirect('/admin/events/' . (int) $params['id']);
    }

    public function excludeMass(Request $request): ResponseInterface
    {
        $grund = trim((string) ($request->post['grund'] ?? ''));
        $ip = trim((string) ($request->post['ip'] ?? ''));
        $editor = trim((string) ($request->post['editor'] ?? ''));
        $von = $this->session->adminUsername() ?? 'admin';

        if ($grund === '' || ($ip === '' && $editor === '')) {
            $this->session->flash('Für die Massenaktion sind IP oder Name plus ein Grund erforderlich.');
        } elseif ($ip !== '') {
            $anzahl = $this->eventStore->excludeByIp($ip, $von, $grund);
            $this->session->flash(sprintf('%d Event(s) der IP %s ausgeschlossen. Danach Rebuild ausführen.', $anzahl, $ip));
        } else {
            $anzahl = $this->eventStore->excludeByEditor($editor, $von, $grund);
            $this->session->flash(sprintf('%d Event(s) von „%s" ausgeschlossen. Danach Rebuild ausführen.', $anzahl, $editor));
        }

        return Response::redirect('/admin/events');
    }

    /**
     * Correction editor: excludes the original and inserts a corrected
     * copy with korrektur_von_event_id (CLAUDE.md section 5).
     *
     * @param array<string, string> $params
     */
    public function correct(Request $request, array $params): ResponseInterface
    {
        $id = (int) $params['id'];
        $payload = json_decode((string) ($request->post['payload'] ?? ''), true);
        if (!is_array($payload)) {
            $this->session->flash('Korrektur abgelehnt: Payload ist kein gültiges JSON-Objekt.');

            return Response::redirect('/admin/events/' . $id);
        }

        try {
            $correction = $this->eventStore->correct($id, $payload, new EventContext(
                $this->session->adminUsername() ?? 'admin',
                $request->ip,
                EventSource::Admin,
            ));
            $this->session->flash(sprintf('Korrektur gespeichert als Event #%d.', $correction->id));

            return Response::redirect('/admin/events/' . $correction->id);
        } catch (\Throwable $e) {
            $this->session->flash('Korrektur fehlgeschlagen: ' . $e->getMessage());

            return Response::redirect('/admin/events/' . $id);
        }
    }
}
