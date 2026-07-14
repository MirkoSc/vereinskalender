<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * Pure-PHP MySQL/MariaDB dump (CLAUDE.md section 12: deliberately
 * self-implemented instead of depending on ifsnop/mysqldump-php):
 * SHOW TABLES, SHOW CREATE TABLE, batched INSERTs. The output restores
 * cleanly through SqlSplitter + PDO::exec.
 */
final readonly class MysqlDumper
{
    private const int ROWS_PER_INSERT = 100;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param resource $stream
     */
    public function dump($stream): void
    {
        fwrite($stream, "-- Vereinskalender DB-Dump\n");
        fwrite($stream, "SET NAMES utf8mb4;\n");
        fwrite($stream, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $this->dumpTable($stream, (string) $table);
        }

        fwrite($stream, "SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    /**
     * @param resource $stream
     */
    private function dumpTable($stream, string $table): void
    {
        fwrite($stream, sprintf("DROP TABLE IF EXISTS `%s`;\n", $table));

        $create = $this->pdo
            ->query(sprintf('SHOW CREATE TABLE `%s`', $table))
            ->fetch();
        fwrite($stream, (string) ($create['Create Table'] ?? '') . ";\n");

        $columns = null;
        $batch = [];
        $select = $this->pdo->query(sprintf('SELECT * FROM `%s`', $table));
        while (($row = $select->fetch()) !== false) {
            $columns ??= '`' . implode('`, `', array_keys($row)) . '`';
            $batch[] = '(' . implode(', ', array_map($this->quoteValue(...), array_values($row))) . ')';

            if (count($batch) >= self::ROWS_PER_INSERT) {
                $this->writeInsert($stream, $table, $columns, $batch);
                $batch = [];
            }
        }
        if ($batch !== [] && $columns !== null) {
            $this->writeInsert($stream, $table, $columns, $batch);
        }

        fwrite($stream, "\n");
    }

    /**
     * @param resource $stream
     * @param list<string> $batch
     */
    private function writeInsert($stream, string $table, string $columns, array $batch): void
    {
        fwrite($stream, sprintf(
            "INSERT INTO `%s` (%s) VALUES\n%s;\n",
            $table,
            $columns,
            implode(",\n", $batch),
        ));
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->pdo->quote((string) $value);
    }
}
