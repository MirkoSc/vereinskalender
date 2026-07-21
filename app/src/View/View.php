<?php

declare(strict_types=1);

namespace App\View;

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
