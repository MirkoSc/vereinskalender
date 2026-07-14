<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Immutable application configuration, loaded from shared/config.php.
 */
final readonly class Config
{
    private function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUser,
        public string $dbPassword,
        public string $bootstrapAdminUsername,
        public string $bootstrapAdminPassword,
        public string $cronToken,
        public bool $debug,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException(sprintf('Config file not found: %s', $path));
        }

        $data = require $path;
        if (!is_array($data)) {
            throw new ConfigException(sprintf('Config file must return an array: %s', $path));
        }

        return self::fromArray($data);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            dbHost: self::stringValue($data, 'db.host'),
            dbPort: self::intValue($data, 'db.port', 3306),
            dbName: self::stringValue($data, 'db.name'),
            dbUser: self::stringValue($data, 'db.user'),
            dbPassword: self::stringValue($data, 'db.password'),
            bootstrapAdminUsername: self::stringValue($data, 'bootstrap_admin.username'),
            bootstrapAdminPassword: self::stringValue($data, 'bootstrap_admin.password'),
            cronToken: self::stringValue($data, 'cron_token'),
            debug: self::boolValue($data, 'debug', false),
        );
    }

    public function dsn(): string
    {
        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->dbHost,
            $this->dbPort,
            $this->dbName,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private static function stringValue(array $data, string $key): string
    {
        $value = self::lookup($data, $key);
        if (!is_string($value) || $value === '') {
            throw new ConfigException(sprintf('Missing or empty config value: %s', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function intValue(array $data, string $key, int $default): int
    {
        $value = self::lookup($data, $key) ?? $default;
        if (!is_int($value)) {
            throw new ConfigException(sprintf('Config value must be an integer: %s', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private static function boolValue(array $data, string $key, bool $default): bool
    {
        $value = self::lookup($data, $key) ?? $default;
        if (!is_bool($value)) {
            throw new ConfigException(sprintf('Config value must be a boolean: %s', $key));
        }

        return $value;
    }

    /**
     * Looks up a dot-separated key ("db.host") in a nested array.
     *
     * @param array<mixed> $data
     */
    private static function lookup(array $data, string $key): mixed
    {
        $current = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
