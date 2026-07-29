<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Config;
use App\Repository\AdminRepository;
use App\Service\Auth\AuthService;
use App\Service\ValidationException;
use App\Tests\Support\DatabaseTestCase;

/**
 * Bootstrap rule (CLAUDE.md section 6): config credentials are ONLY valid
 * while the admin table is empty — no flag, no state.
 */
final class BootstrapAdminTest extends DatabaseTestCase
{
    private function auth(): AuthService
    {
        $config = Config::fromArray([
            'db' => [
                'host' => 'unused', 'name' => 'unused', 'user' => 'unused', 'password' => 'unused',
            ],
            'bootstrap_admin' => [
                'username' => 'bootstrap',
                'password' => 'bootstrap-geheim',
            ],
            'cron_token' => 'unused',
        ]);

        return new AuthService(new AdminRepository($this->pdo()), $config);
    }

    public function testBootstrapCredentialsWorkOnlyWhileAdminTableIsEmpty(): void
    {
        $auth = $this->auth();

        self::assertTrue($auth->attempt('bootstrap', 'bootstrap-geheim')->isBootstrap);
        self::assertFalse($auth->attempt('bootstrap', 'falsch')->succeeded());

        $auth->createFirstAdmin('chef', 'sehr-sicheres-passwort', 'sehr-sicheres-passwort');

        // as soon as one row exists, bootstrap credentials are rejected
        self::assertFalse($auth->attempt('bootstrap', 'bootstrap-geheim')->succeeded());

        $result = $auth->attempt('chef', 'sehr-sicheres-passwort');
        self::assertTrue($result->isAdmin);
        self::assertSame('chef', $result->username);

        self::assertFalse($auth->attempt('chef', 'falsches-passwort')->succeeded());
    }

    public function testPasswordIsStoredAsHash(): void
    {
        $auth = $this->auth();
        $auth->createFirstAdmin('chef', 'sehr-sicheres-passwort', 'sehr-sicheres-passwort');

        $hash = (string) $this->pdo()
            ->query('SELECT password_hash FROM admin')
            ->fetchColumn();

        self::assertStringNotContainsString('sehr-sicheres-passwort', $hash);
        self::assertTrue(password_verify('sehr-sicheres-passwort', $hash));
    }

    /**
     * A successful login is the only moment the plaintext is available, so
     * it is the only place a stored hash can follow a PASSWORD_DEFAULT
     * change. Simulated with an explicitly weak bcrypt cost: nothing else
     * ever rewrites the column, so without this an account would keep the
     * algorithm that was current when its password was set, forever.
     */
    public function testLoginUpgradesAnOutdatedHash(): void
    {
        $auth = $this->auth();
        $auth->createFirstAdmin('chef', 'sehr-sicheres-passwort', 'sehr-sicheres-passwort');

        $veraltet = password_hash('sehr-sicheres-passwort', PASSWORD_BCRYPT, ['cost' => 5]);
        $this->pdo()->exec("UPDATE admin SET password_hash = " . $this->pdo()->quote($veraltet));
        self::assertTrue(password_needs_rehash($veraltet, PASSWORD_DEFAULT), 'sanity: the fixture really is outdated');

        $result = $auth->attempt('chef', 'sehr-sicheres-passwort');

        self::assertTrue($result->isAdmin);
        $neu = (string) $this->pdo()->query('SELECT password_hash FROM admin')->fetchColumn();
        self::assertNotSame($veraltet, $neu, 'the hash was rewritten');
        self::assertFalse(password_needs_rehash($neu, PASSWORD_DEFAULT));
        self::assertTrue(password_verify('sehr-sicheres-passwort', $neu), 'and still matches the same password');
    }

    public function testAnUpToDateHashIsLeftAlone(): void
    {
        $auth = $this->auth();
        $auth->createFirstAdmin('chef', 'sehr-sicheres-passwort', 'sehr-sicheres-passwort');
        $vorher = (string) $this->pdo()->query('SELECT password_hash FROM admin')->fetchColumn();

        $auth->attempt('chef', 'sehr-sicheres-passwort');

        self::assertSame($vorher, (string) $this->pdo()->query('SELECT password_hash FROM admin')->fetchColumn());
    }

    public function testCreateFirstAdminValidatesInput(): void
    {
        $auth = $this->auth();

        try {
            $auth->createFirstAdmin('ab', 'kurz', 'anders');
            self::fail('Expected validation to fail');
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            self::assertArrayHasKey('username', $errors);
            self::assertArrayHasKey('password', $errors);
            self::assertArrayHasKey('password_repeat', $errors);
        }
    }

    public function testCreateFirstAdminRefusesWhenAdminExists(): void
    {
        $auth = $this->auth();
        $auth->createFirstAdmin('chef', 'sehr-sicheres-passwort', 'sehr-sicheres-passwort');

        $this->expectException(ValidationException::class);

        $auth->createFirstAdmin('zweiter', 'sehr-sicheres-passwort', 'sehr-sicheres-passwort');
    }
}
