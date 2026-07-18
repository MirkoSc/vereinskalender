<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Request;
use App\Http\Response;
use App\Repository\UsageStatRepository;

/**
 * navigator.sendBeacon target (CLAUDE.md section 6): accepts ONLY a
 * whitelist of fixed metric names, no free-form data.
 */
final readonly class StatController
{
    private const array METRIKEN = ['filternutzung', 'push_abo_dialog', 'pwa_installation', 'platzauswahl'];

    public function __construct(private UsageStatRepository $stats)
    {
    }

    public function beacon(Request $request): Response
    {
        $metrik = trim((string) ($request->post['metrik'] ?? ''));
        if (!in_array($metrik, self::METRIKEN, true)) {
            return Response::json(['fehler' => 'Unbekannte Metrik.'], 422);
        }

        $this->stats->increment('feature_' . $metrik);

        return Response::json(['ok' => true]);
    }
}
