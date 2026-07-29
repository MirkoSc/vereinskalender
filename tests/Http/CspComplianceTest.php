<?php

declare(strict_types=1);

namespace App\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Content-Security-Policy the docroot .htaccess sends
 * (CLAUDE.md section 2). The policy restricts scripts to same-origin files,
 * which only holds as long as no template reintroduces an inline
 * <script> block or an inline event handler.
 *
 * That failure mode is the reason this test exists rather than a manual
 * check: a browser enforcing script-src silently DROPS inline handlers. The
 * page keeps rendering, the button keeps looking clickable - the delete
 * confirmation just never appears and the form submits straight through.
 * Nothing in the app would notice.
 */
final class CspComplianceTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private static function viewFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::repoRoot() . '/app/views', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function htaccess(): string
    {
        return (string) file_get_contents(self::repoRoot() . '/docker/web/.htaccess');
    }

    private static function csp(): string
    {
        preg_match('/Content-Security-Policy "([^"]+)"/', self::htaccess(), $m);
        self::assertNotEmpty($m, 'no Content-Security-Policy header in docker/web/.htaccess');

        return $m[1];
    }

    /**
     * Inline handlers (onclick, onsubmit, ...) are dead under script-src.
     * The delete confirmations use data-confirm plus one delegated listener
     * in admin.js instead; new forms only need the attribute.
     */
    public function testNoViewUsesAnInlineEventHandler(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            $inhalt = (string) file_get_contents($file);
            if (preg_match_all('/\son[a-z]+\s*=\s*"/i', $inhalt, $m) > 0) {
                $treffer[] = basename($file) . ': ' . implode(', ', $m[0]);
            }
        }

        self::assertSame(
            [],
            $treffer,
            "Inline event handlers are silently disabled by script-src.\n"
            . "Use data-confirm=\"…\" (admin.js listens for it) or a listener in public/js/.",
        );
    }

    /**
     * <script> with a body is blocked too. type="application/json" is fine -
     * it is data the page reads, never executed (layout.php's app-data
     * block).
     */
    public function testNoViewEmbedsAnExecutableInlineScript(): void
    {
        $treffer = [];
        foreach (self::viewFiles() as $file) {
            preg_match_all('/<script(?<attrs>[^>]*)>/i', (string) file_get_contents($file), $m);
            foreach ($m['attrs'] as $attrs) {
                if (str_contains($attrs, 'src=') || str_contains($attrs, 'application/json')) {
                    continue;
                }
                $treffer[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $treffer,
            "Inline <script> is blocked by script-src 'self'. Move it to public/js/ and pass it via 'scripts'.",
        );
    }

    public function testPolicyRestrictsScriptsToSameOrigin(): void
    {
        $csp = self::csp();

        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString(
            'unsafe-eval',
            $csp,
            "nothing needs it - neither our JS nor the FullCalendar bundle calls eval()/new Function()",
        );

        // 'unsafe-inline' is allowed for STYLES only: layout.php emits the
        // palette colours as a <style> block, the admin lists use
        // style="background: …" swatches, and FullCalendar injects a
        // stylesheet at runtime. It must never leak into script-src.
        preg_match('/script-src ([^;]+)/', $csp, $m);
        self::assertStringNotContainsString('unsafe-inline', $m[1] ?? '');
    }

    public function testPolicyKeepsTheDirectivesThatNeedNoScriptChanges(): void
    {
        $csp = self::csp();

        foreach (["default-src 'self'", "object-src 'none'", "frame-ancestors 'none'", "base-uri 'self'", "form-action 'self'"] as $direktive) {
            self::assertStringContainsString($direktive, $csp);
        }
    }

    /**
     * font-src has to allow data:, and the reason is invisible in our own
     * source: FullCalendar ships its icon font (the prev/next chevrons) as a
     * base64 data: URI inside the stylesheet it injects at runtime. Without
     * this the arrows render as empty boxes - the page still "works", so
     * only opening it in a browser catches it. Pinned here so a later
     * tightening pass cannot quietly drop it again.
     */
    public function testPolicyAllowsTheFullCalendarIconFont(): void
    {
        self::assertStringContainsString("font-src 'self' data:", self::csp());
    }

    /**
     * Same drift risk as the shim (see ShimContentTest): the .htaccess a
     * fresh install writes and the one the dev environment runs must be the
     * same file, or the CSP is only ever exercised in one of them.
     */
    public function testFreshInstallWritesTheSameHtaccessAsTheDevEnvironment(): void
    {
        foreach (['bin/setup.template.php', 'setup.php'] as $quelle) {
            self::assertSame(
                self::htaccess(),
                self::nowdocFrom(self::repoRoot() . '/' . $quelle, 'HTACCESS'),
                $quelle . ' would write a different .htaccess than docker/web/',
            );
        }
    }

    /**
     * Extracts a nowdoc by marker and lets PHP evaluate it, so the closing
     * marker's indentation is stripped by the real parser rather than by a
     * reimplementation here (same approach as ShimContentTest).
     */
    private static function nowdocFrom(string $file, string $marker): string
    {
        $source = (string) file_get_contents($file);

        $start = strpos($source, "<<<'" . $marker . "'");
        self::assertNotFalse($start, sprintf('no <<<\'%s\' nowdoc in %s', $marker, $file));

        $end = strpos($source, $marker . ');', $start);
        self::assertNotFalse($end, sprintf('unterminated %s nowdoc in %s', $marker, $file));

        eval('$extracted = ' . substr($source, $start, $end - $start + strlen($marker)) . ';');

        /** @var string $extracted */
        return $extracted;
    }
}
