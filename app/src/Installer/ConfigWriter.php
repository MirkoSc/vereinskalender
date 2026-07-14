<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * Writes shared/config.php - the last installer step: its existence locks
 * /install (CLAUDE.md section 10).
 */
final class ConfigWriter
{
    /**
     * @param array<string, mixed> $db host/port/name/user/password
     */
    public static function write(
        string $configFile,
        array $db,
        string $bootstrapUsername,
        string $bootstrapPassword,
    ): void {
        $config = [
            'debug' => false,
            'db' => [
                'host' => (string) $db['host'],
                'port' => (int) $db['port'],
                'name' => (string) $db['name'],
                'user' => (string) $db['user'],
                'password' => (string) $db['password'],
            ],
            'bootstrap_admin' => [
                'username' => $bootstrapUsername,
                'password' => $bootstrapPassword,
            ],
            'cron_token' => bin2hex(random_bytes(24)),
        ];

        $dir = dirname($configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $content = "<?php\n\n// Written by the installer. Persists across updates (lives in shared/).\nreturn "
            . var_export($config, true) . ";\n";

        if (file_put_contents($configFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException('config.php kann nicht geschrieben werden: ' . $configFile);
        }
    }
}
