<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Request::httpsFromGlobals() decides whether the admin session cookie
 * carries the `secure` flag (Session::start()). Getting it wrong in either
 * direction is costly and silent: a false negative on an HTTPS host means
 * the session cookie travels over plain HTTP if anyone ever hits http://,
 * a false positive on the docker dev setup means the browser drops the
 * cookie and every login bounces back to the form with no error.
 *
 * Apache/CGI spell the value differently ("on", "1", ""), hence the matrix.
 */
final class RequestHttpsTest extends TestCase
{
    private mixed $previous = null;
    private bool $wasSet = false;

    protected function setUp(): void
    {
        $this->wasSet = array_key_exists('HTTPS', $_SERVER);
        $this->previous = $_SERVER['HTTPS'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->wasSet) {
            $_SERVER['HTTPS'] = $this->previous;
        } else {
            unset($_SERVER['HTTPS']);
        }
    }

    /** @return list<array{string, bool}> */
    public static function values(): array
    {
        return [
            ['on', true],
            ['On', true],
            ['ON', true],
            ['1', true],
            // CGI/FastCGI set the variable to the literal "off" rather than
            // leaving it unset - treating that as HTTPS would mark the cookie
            // secure on a plain-HTTP host and lock the admin out.
            ['off', false],
            ['Off', false],
            ['', false],
        ];
    }

    #[DataProvider('values')]
    public function testHttpsDetection(string $serverValue, bool $expected): void
    {
        $_SERVER['HTTPS'] = $serverValue;

        self::assertSame($expected, Request::httpsFromGlobals());
    }

    /**
     * Plain HTTP leaves the variable unset entirely. Answering false there
     * is the safe direction: it only ever means "no improvement over today",
     * never a cookie the browser refuses to store.
     */
    public function testMissingVariableMeansNoHttps(): void
    {
        unset($_SERVER['HTTPS']);

        self::assertFalse(Request::httpsFromGlobals());
    }
}
