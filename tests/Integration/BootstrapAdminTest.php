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
