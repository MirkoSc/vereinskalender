<?php

declare(strict_types=1);

namespace App\Tests\Installer;

use App\Config\Config;
use App\Installer\ConfigWriter;
use PHPUnit\Framework\TestCase;

final class ConfigWriterTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/vk_config_' . uniqid('', true) . '.php';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testWrittenConfigLoadsThroughConfigClass(): void
    {
        ConfigWriter::write(
            $this->file,
            ['host' => 'db.example', 'port' => 3307, 'name' => 'verein', 'user' => 'kal', 'password' => "ge'heim\\x"],
            'bootstrap',
            'sehr-geheimes-passwort',
        );

        $config = Config::fromFile($this->file);

        self::assertSame('db.example', $config->dbHost);
        self::assertSame(3307, $config->dbPort);
        self::assertSame("ge'heim\\x", $config->dbPassword, 'special characters survive var_export');
        self::assertSame('bootstrap', $config->bootstrapAdminUsername);
        self::assertFalse($config->debug);
        self::assertSame(48, strlen($config->cronToken), 'random cron token is generated');
    }
}
