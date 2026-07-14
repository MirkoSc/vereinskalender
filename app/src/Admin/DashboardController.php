<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\Request;
use App\Http\ResponseInterface;
use App\Http\Session;
use App\Repository\ImportSourceRepository;
use App\Repository\PitchRepository;
use App\Repository\PushSubscriptionRepository;
use App\Repository\TeamRepository;
use App\Repository\UsageStatRepository;
use App\Repository\VenueRepository;
use App\Service\EventStore\EventStore;
use App\View\View;

/**
 * Admin dashboard with usage statistics and operations monitoring
 * (CLAUDE.md section 6): "last import X minutes ago" (green < 30, red >
 * 60 = cron dead), per-feed warnings incl. the season-end indicator (no
 * future matches), plus aggregated feature counters.
 */
final class DashboardController extends AdminController
{
    public function __construct(
        View $view,
        Session $session,
        private readonly TeamRepository $teams,
        private readonly PitchRepository $pitches,
        private readonly VenueRepository $venues,
        private readonly EventStore $eventStore,
        private readonly UsageStatRepository $stats,
        private readonly ImportSourceRepository $sources,
        private readonly PushSubscriptionRepository $pushSubscriptions,
        private readonly \PDO $pdo,
    ) {
        parent::__construct($view, $session);
    }

    public function index(Request $request): ResponseInterface
    {
        return $this->render('admin/dashboard', [
            'title' => 'Admin',
            'teamCount' => $this->teams->count(),
            'pitchCount' => $this->pitches->count(),
            'venueCount' => $this->venues->count(),
            'eventCount' => $this->eventStore->countActive(),
            'pushCount' => $this->pushSubscriptions->count(),
            'seitenaufrufe' => $this->stats->summary('seite'),
            'apiAbrufe' => $this->stats->summary('api'),
            'feedAbrufe' => $this->stats->summary('ics_feed'),
            'tagesverlauf' => $this->stats->dailyTotals('seite'),
            'topRouten' => $this->stats->topDimensions('seite'),
            'topFeeds' => $this->stats->topDimensions('ics_feed'),
            'featureZaehler' => [
                'Moduswechsel' => $this->stats->summary('feature_moduswechsel')['tage30'],
                'Filternutzung' => $this->stats->summary('feature_filternutzung')['tage30'],
                'Push-Abo-Dialog' => $this->stats->summary('feature_push_abo_dialog')['tage30'],
                'PWA-Installation' => $this->stats->summary('feature_pwa_installation')['tage30'],
                'Offline-Bundle' => $this->stats->summary('offline_bundle')['tage30'],
            ],
            'monitoring' => $this->monitoring(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function monitoring(): array
    {
        $sources = $this->sources->findAll();

        $letzterLauf = null;
        foreach ($sources as $source) {
            if ($source['letzter_lauf'] !== null
                && ($letzterLauf === null || (string) $source['letzter_lauf'] > $letzterLauf)) {
                $letzterLauf = (string) $source['letzter_lauf'];
            }
        }

        $minuten = null;
        $ampel = 'grau';
        if ($letzterLauf !== null) {
            $minuten = (int) floor((time() - new \DateTimeImmutable($letzterLauf)->getTimestamp()) / 60);
            $ampel = $minuten < 30 ? 'gruen' : ($minuten <= 60 ? 'gelb' : 'rot');
        }

        $warnungen = [];
        $future = $this->pdo->prepare(
            "SELECT COUNT(*) FROM `match`
             WHERE import_source_id = ? AND anstoss > NOW() AND status <> 'abgesagt'",
        );
        foreach ($sources as $source) {
            $name = (string) ($source['team_name'] ?? ('Quelle #' . $source['id']));
            if ((int) $source['aktiv'] !== 1) {
                continue;
            }
            if ($source['letzter_status'] === 'fehler') {
                $warnungen[] = sprintf('%s: Import fehlerhaft – %s', $name, (string) ($source['fehlertext'] ?? ''));
                continue;
            }
            $future->execute([(int) $source['id']]);
            if ((int) $future->fetchColumn() === 0 && $source['letzter_lauf'] !== null) {
                $warnungen[] = sprintf('%s: Feed liefert keine Zukunftstermine mehr (Saisonende? URL erneuern).', $name);
            }
        }

        return [
            'letzter_import_minuten' => $minuten,
            'ampel' => $ampel,
            'warnungen' => $warnungen,
            'aktive_quellen' => count(array_filter($sources, static fn(array $s): bool => (int) $s['aktiv'] === 1)),
        ];
    }
}
