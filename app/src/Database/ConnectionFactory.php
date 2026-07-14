<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\Config;

final class ConnectionFactory
{
    public static function create(Config $config): \PDO
    {
        return new \PDO($config->dsn(), $config->dbUser, $config->dbPassword, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }
}
