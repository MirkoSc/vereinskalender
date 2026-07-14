<?php

declare(strict_types=1);

namespace App\Tests\Service\Migration;

use App\Service\Migration\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsTwoStatements(): void
    {
        $sql = "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT);";

        self::assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'],
            SqlSplitter::split($sql),
        );
    }

    public function testKeepsSemicolonsInsideSingleQuotedStrings(): void
    {
        $sql = "INSERT INTO t (a) VALUES ('x;y');";

        self::assertSame(["INSERT INTO t (a) VALUES ('x;y')"], SqlSplitter::split($sql));
    }

    public function testKeepsSemicolonsInsideDoubleQuotesAndBackticks(): void
    {
        $sql = 'CREATE TABLE `a;b` (id INT); SELECT "x;y";';

        self::assertSame(
            ['CREATE TABLE `a;b` (id INT)', 'SELECT "x;y"'],
            SqlSplitter::split($sql),
        );
    }

    public function testIgnoresLineComments(): void
    {
        $sql = "-- comment; with semicolon\nSELECT 1;\n# another; comment\nSELECT 2;";

        self::assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split($sql));
    }

    public function testIgnoresBlockComments(): void
    {
        $sql = "/* multi;\nline; comment */SELECT 1;";

        self::assertSame(['SELECT 1'], SqlSplitter::split($sql));
    }

    public function testHandlesEscapedQuoteInsideString(): void
    {
        $sql = "INSERT INTO t VALUES ('it\\'s; fine');";

        self::assertSame(["INSERT INTO t VALUES ('it\\'s; fine')"], SqlSplitter::split($sql));
    }

    public function testDropsEmptyTrailingStatement(): void
    {
        self::assertSame(['SELECT 1'], SqlSplitter::split("SELECT 1;\n\n  \n"));
    }

    public function testStatementWithoutTrailingSemicolonIsKept(): void
    {
        self::assertSame(['SELECT 1'], SqlSplitter::split('SELECT 1'));
    }
}
