<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\HttpMethod;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\ResponseInterface;
use App\Http\Router;
use App\Http\StaticFileHandler;
use App\View\View;
use PHPUnit\Framework\TestCase;

/**
 * W1 (CLAUDE.md section 5, "Passwörter nie loggen"): the global error handler
 * must never write the full exception string. A stack trace carries function
 * arguments when zend.exception_ignore_args is off, so a failing login would
 * otherwise log the plaintext password. The handler logs class, message and
 * request context only - the guarantee holds regardless of that ini setting.
 */
final class KernelErrorLogTest extends TestCase
{
    public function testErrorLogNeverContainsFunctionArgumentsLikePasswords(): void
    {
        // Force PHP to KEEP args in traces, i.e. simulate a host that leaves
        // zend.exception_ignore_args off - the guarantee must hold anyway.
        $previousIgnore = ini_set('zend.exception_ignore_args', '0');
        $logFile = tempnam(sys_get_temp_dir(), 'kernel_errlog_');
        $previousLog = ini_set('error_log', $logFile);

        try {
            $secret = 'sup3r-secret-passw0rd';

            $router = new Router();
            $router->post('/admin/login', static function (Request $r, array $p) use ($secret): ResponseInterface {
                // the password travels as a call argument, exactly like the
                // real AuthController -> AuthService::attempt() chain
                $attempt = static function (string $username, string $password): ResponseInterface {
                    throw new \RuntimeException('database unavailable');
                };

                return $attempt('chef', $secret);
            });

            $kernel = new Kernel(
                $router,
                new StaticFileHandler(sys_get_temp_dir(), longCache: false),
                new View(dirname(__DIR__, 2) . '/app/views', '0.0.0-test'),
                debug: false,
            );

            $response = $kernel->handle(new Request(HttpMethod::Post, '/admin/login', ip: '203.0.113.9'));

            self::assertSame(500, $response->status);
            self::assertStringNotContainsString($secret, $response->body);

            $logged = (string) file_get_contents($logFile);
            self::assertNotSame('', $logged, 'the error was logged');
            self::assertStringNotContainsString($secret, $logged, 'no plaintext password in the log');
            self::assertStringContainsString('RuntimeException', $logged);
            self::assertStringContainsString('database unavailable', $logged);
            self::assertStringContainsString('POST', $logged);
            self::assertStringContainsString('/admin/login', $logged);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            ini_set('zend.exception_ignore_args', $previousIgnore === false ? '1' : $previousIgnore);
            @unlink($logFile);
        }
    }
}
