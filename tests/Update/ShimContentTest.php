<?php

declare(strict_types=1);

namespace App\Tests\Update;

use App\Service\Update\ReleaseSwitcher;
use PHPUnit\Framework\TestCase;

/**
 * The docroot shim exists in three places and must be byte-identical in all
 * of them (CLAUDE.md section 2):
 *
 *  - ReleaseSwitcher::SHIM        - what the updater self-heals to
 *  - bin/setup.template.php       - what a fresh install writes
 *  - docker/web/index.php         - what the dev environment runs
 *
 * Drift here is unusually expensive: the shim is the only file that answers
 * while current/ is mid-swap, it is not versioned, and a rollback cannot
 * restore it. A broken shim takes down the entire site INCLUDING /admin, so
 * there would be no way back through the app itself.
 */
final class ShimContentTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Extracts a nowdoc by marker from a PHP source file and lets PHP itself
     * evaluate it. Deliberately eval() and not a hand-rolled dedent: the
     * closing marker's indentation is stripped by the PHP parser, and
     * reimplementing that here could agree with itself while disagreeing
     * with what actually ends up on disk.
     */
    private static function nowdocFrom(string $file, string $marker): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source, 'cannot read ' . $file);

        $start = strpos($source, "<<<'" . $marker . "'");
        self::assertNotFalse($start, sprintf('no <<<\'%s\' nowdoc in %s', $marker, $file));

        $end = strpos($source, $marker . ');', $start);
        self::assertNotFalse($end, sprintf('unterminated %s nowdoc in %s', $marker, $file));

        $block = substr($source, $start, $end - $start + strlen($marker));
        eval('$extracted = ' . $block . ';');

        /** @var string $extracted */
        return $extracted;
    }

    public function testDockerShimMatchesTheConstant(): void
    {
        self::assertSame(
            ReleaseSwitcher::SHIM,
            file_get_contents(self::repoRoot() . '/docker/web/index.php'),
            'docker/web/index.php drifted from ReleaseSwitcher::SHIM',
        );
    }

    public function testSetupTemplateWritesTheSameShim(): void
    {
        self::assertSame(
            ReleaseSwitcher::SHIM,
            self::nowdocFrom(self::repoRoot() . '/bin/setup.template.php', 'SHIM'),
            'bin/setup.template.php would write a different shim than the updater self-heals to',
        );
    }

    /**
     * setup.php is generated from the template, and CI already checks it is
     * fresh - but a fresh install runs THIS file, so it is worth asserting
     * directly rather than by transitivity.
     */
    public function testGeneratedSetupWritesTheSameShim(): void
    {
        self::assertSame(
            ReleaseSwitcher::SHIM,
            self::nowdocFrom(self::repoRoot() . '/setup.php', 'SHIM'),
            'setup.php is stale - run php bin/build_setup.php',
        );
    }

    /**
     * A syntax error in the shim would be catastrophic and is invisible to
     * every other check: the string is never linted, and exec() is not
     * available on the target hosting, so nothing can shell out to `php -l`.
     * token_get_all() with TOKEN_PARSE runs the real parser in-process and
     * throws ParseError on invalid code.
     */
    public function testShimIsSyntacticallyValidPhp(): void
    {
        token_get_all(ReleaseSwitcher::SHIM, \TOKEN_PARSE);

        self::assertStringStartsWith('<?php', ReleaseSwitcher::SHIM);
        self::assertStringEndsWith("\n", ReleaseSwitcher::SHIM, 'must end with a newline');
    }

    /**
     * The two conditions the shim exists for. Pinned as strings because both
     * are easy to drop by accident while "tidying up" the file, and neither
     * failure is visible until an update is already in flight.
     */
    public function testShimGuardsBothMissingReleaseAndMaintenanceFlag(): void
    {
        self::assertStringContainsString('!is_file($release)', ReleaseSwitcher::SHIM);
        self::assertStringContainsString("shared/maintenance.flag", ReleaseSwitcher::SHIM);
        self::assertStringContainsString("str_starts_with(\$pfad, '/admin')", ReleaseSwitcher::SHIM);
        self::assertStringContainsString('http_response_code(503)', ReleaseSwitcher::SHIM);
    }
}
