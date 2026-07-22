<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Service\RateLimiter;
use App\Tests\Support\DatabaseTestCase;

/**
 * W2 (CLAUDE.md section 5): admin login/setup is brute-force throttled per IP.
 * Only failed attempts count, a successful login resets the counter, and the
 * limit is stricter than the public write path. The counter lives under a
 * separate, hashed key so it never touches the write budget for the same IP.
 */
final class RateLimiterLoginTest extends DatabaseTestCase
{
    public function testLocksAfterLimitFailedAttemptsAndResetsOnSuccess(): void
    {
        $limiter = new RateLimiter($this->pdo());
        $ip = '198.51.100.7';

        self::assertFalse($limiter->loginLocked($ip));

        // one below the limit: still allowed
        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_MINUTE - 1; $i++) {
            $limiter->registerLoginFailure($ip);
        }
        self::assertFalse($limiter->loginLocked($ip), 'not locked one below the limit');

        $limiter->registerLoginFailure($ip); // reaches the limit
        self::assertTrue($limiter->loginLocked($ip), 'locked once the limit is reached');

        // a successful login clears the counter
        $limiter->resetLogin($ip);
        self::assertFalse($limiter->loginLocked($ip), 'a successful login resets the throttle');
    }

    public function testThrottleIsPerIp(): void
    {
        $limiter = new RateLimiter($this->pdo());
        $locked = '198.51.100.1';
        $other = '198.51.100.2';

        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_MINUTE; $i++) {
            $limiter->registerLoginFailure($locked);
        }

        self::assertTrue($limiter->loginLocked($locked));
        self::assertFalse($limiter->loginLocked($other), 'another IP is unaffected');
    }

    public function testLoginThrottleIsSeparateFromTheWriteBudget(): void
    {
        $limiter = new RateLimiter($this->pdo());
        $ip = '198.51.100.3';

        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_MINUTE; $i++) {
            $limiter->registerLoginFailure($ip);
        }

        self::assertTrue($limiter->loginLocked($ip));
        // the public write limiter for the SAME raw IP is untouched (separate
        // hashed key), so login failures never consume the write budget
        self::assertTrue($limiter->allow($ip), 'login failures do not consume the write budget');
    }

    public function testEmptyIpIsNeverLocked(): void
    {
        $limiter = new RateLimiter($this->pdo());

        for ($i = 0; $i < RateLimiter::LOGIN_LIMIT_PER_MINUTE + 5; $i++) {
            $limiter->registerLoginFailure('');
        }

        self::assertFalse($limiter->loginLocked(''));
    }

    public function testLoginLimitIsStricterThanTheWriteLimit(): void
    {
        self::assertLessThan(RateLimiter::LIMIT_PER_MINUTE, RateLimiter::LOGIN_LIMIT_PER_MINUTE);
    }
}
