<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Service\Import\IcsImportService;
use App\Service\Import\ImportSourceResult;

/**
 * Cron entry point (CLAUDE.md section 7): the hoster's control-panel cronjob calls
 * this URL every 10 minutes with the secret token from shared/config.php.
 * No session, no cookies.
 */
final readonly class CronController
{
    public function __construct(
        private Config $config,
        private IcsImportService $import,
    ) {
    }

    public function import(Request $request): Response
    {
        $token = (string) ($request->query['token'] ?? '');
        if ($token === '' || !hash_equals($this->config->cronToken, $token)) {
            return Response::json(['fehler' => 'Ungültiges Token.'], 403);
        }

        $results = $this->import->runAll();

        return Response::json([
            'status' => 'ok',
            'quellen' => array_map(
                static fn(ImportSourceResult $r): array => $r->toArray(),
                $results,
            ),
        ]);
    }
}
