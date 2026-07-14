<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Repository\EventHistoryRepository;
use App\Repository\SettingRepository;
use App\Service\Import\IcsImportService;
use App\Service\Import\ImportSourceResult;
use App\Service\Push\PushSender;
use App\Service\RateLimiter;

/**
 * Cron entry point (CLAUDE.md sections 7/9): the all-inkl KAS cronjob
 * calls this URL every 10 minutes with the secret token. One run does:
 * ICS import -> push delivery -> IP anonymisation -> rate limit cleanup.
 */
final readonly class CronController
{
    public function __construct(
        private Config $config,
        private IcsImportService $import,
        private PushSender $pushSender,
        private EventHistoryRepository $eventHistory,
        private SettingRepository $settings,
        private RateLimiter $rateLimiter,
    ) {
    }

    public function import(Request $request): Response
    {
        $token = (string) ($request->query['token'] ?? '');
        if ($token === '' || !hash_equals($this->config->cronToken, $token)) {
            return Response::json(['fehler' => 'Ungültiges Token.'], 403);
        }

        $results = $this->import->runAll();

        $push = ['verarbeitet' => 0, 'gesendet' => 0, 'entfernt' => 0];
        try {
            $push = $this->pushSender->processQueue();
        } catch (\Throwable $e) {
            $push = ['fehler' => $e->getMessage()];
        }

        $tage = max(1, (int) $this->settings->get('ip_aufbewahrung_tage', '90'));
        $anonymisiert = $this->eventHistory->anonymizeOldIps($tage);
        $this->rateLimiter->cleanup();

        return Response::json([
            'status' => 'ok',
            'quellen' => array_map(
                static fn(ImportSourceResult $r): array => $r->toArray(),
                $results,
            ),
            'push' => $push,
            'ips_anonymisiert' => $anonymisiert,
        ]);
    }
}
