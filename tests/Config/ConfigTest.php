<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\Config;
use App\Config\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function validData(): array
    {
        return [
            'db' => [
                'host' => 'db',
                'port' => 3307,
                'name' => 'vereinskalender',
                'user' => 'kalender',
                'password' => 'secret',
            ],
            'bootstrap_admin' => [
                'username' => 'admin',
                'password' => 'admin-secret',
            ],
            'cron_token' => 'token',
        ];
    }

    public function testFromArrayReadsAllValues(): void
    {
        $config = Config::fromArray(self::validData());

        self::assertSame('db', $config->dbHost);
        self::assertSame(3307, $config->dbPort);
        self::assertSame('vereinskalender', $config->dbName);
        self::assertSame('kalender', $config->dbUser);
        self::assertSame('secret', $config->dbPassword);
        self::assertSame('admin', $config->bootstrapAdminUsername);
        self::assertSame('admin-secret', $config->bootstrapAdminPassword);
        self::assertSame('token', $config->cronToken);
        self::assertFalse($config->debug);
    }

    public function testDbPortDefaultsTo3306(): void
    {
        $data = self::validData();
        unset($data['db']['port']);

        self::assertSame(3306, Config::fromArray($data)->dbPort);
    }

    public function testMissingDbPasswordThrowsWithKeyName(): void
    {
        $data = self::validData();
        unset($data['db']['password']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('db.password');

        Config::fromArray($data);
    }

    public function testDsnContainsUtf8mb4Charset(): void
    {
        $dsn = Config::fromArray(self::validData())->dsn();

        self::assertSame('mysql:host=db;port=3307;dbname=vereinskalender;charset=utf8mb4', $dsn);
    }

    public function testFromFileLoadsArrayReturningFile(): void
    {
        $config = Config::fromFile(__DIR__ . '/../fixtures/config/config.php');

        self::assertSame('fixture-host', $config->dbHost);
        self::assertTrue($config->debug);
    }

    public function testFromFileMissingThrows(): void
    {
        $this->expectException(ConfigException::class);

        Config::fromFile(__DIR__ . '/../fixtures/config/does_not_exist.php');
    }
}
