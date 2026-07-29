<?php

declare(strict_types=1);

namespace App\View;

use App\Service\MaintenanceMode;

/**
 * Minimal template renderer: plain PHP templates inside a layout.
 * Data keys become local variables in the template (EXTR_SKIP: the
 * reserved names $file and $data cannot be overridden).
 */
final readonly class View
{
    public function __construct(
        private string $viewsDir,
        private string $version,
        private bool $wappenVorhanden = false,
        private string $wappenVersion = '0',
        private string $appName = 'Vereinskalender',
        private ?MaintenanceMode $maintenance = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->renderFile($this->viewsDir . '/' . $template . '.php', $data);

        return $this->renderFile(
            $this->viewsDir . '/' . $layout . '.php',
            [
                ...$data,
                'content' => $content,
                'version' => $this->version,
                'wappenVorhanden' => $this->wappenVorhanden,
                'wappenVersion' => $this->wappenVersion,
                'appName' => $this->appName,
                // Global like appName: the admin layout must show the
                // maintenance banner on EVERY page, not just on /admin/update
                // and /admin/rebuild - the flag can be left behind by either
                // and the way out has to be reachable from wherever you are.
                // Only ever rendered in the admin layout: while the flag is
                // set the public side is answered with a 503 by the shim.
                'wartung' => $this->maintenance?->state(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
