<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MigratorParseTest extends TestCase
{
    public function testSplitsUpAndDownSections(): void
    {
        $sql = <<<SQL
            -- Création de la table
            -- migrate:up
            CREATE TABLE t (id int);
            -- migrate:down
            DROP TABLE t;
            SQL;

        self::assertSame(
            ['up' => 'CREATE TABLE t (id int);', 'down' => 'DROP TABLE t;'],
            Migrator::parse($sql),
        );
    }

    public function testDownSectionIsOptional(): void
    {
        self::assertSame('', Migrator::parse("-- migrate:up\nSELECT 1;")['down']);
    }

    public function testMissingUpSectionIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        Migrator::parse("CREATE TABLE t (id int);");
    }
}
