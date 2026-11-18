<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database\Migrator;
use PDOException;

final class MigrationTest extends IntegrationTestCase
{
    public function test_migrations_apply_once_and_are_tracked(): void
    {
        $this->dropAllTables();

        $migrator = new Migrator($this->db, $this->migrationsPath());

        self::assertSame(
            ['001_users.sql', '002_products.sql', '003_transactions.sql'],
            $migrator->run()
        );
        self::assertSame([], $migrator->run());
    }

    public function test_database_rejects_negative_price(): void
    {
        $this->expectException(PDOException::class);

        $this->db->pdo()->exec(
            "INSERT INTO products (name, slug, price, quantity_available)
             VALUES ('Bad', 'bad', -1, 0)"
        );
    }

    public function test_database_rejects_duplicate_slug(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec(
            "INSERT INTO products (name, slug, price, quantity_available)
             VALUES ('One', 'same-slug', 1, 1)"
        );

        $this->expectException(PDOException::class);

        $pdo->exec(
            "INSERT INTO products (name, slug, price, quantity_available)
             VALUES ('Two', 'same-slug', 1, 1)"
        );
    }

    private function dropAllTables(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['transactions', 'products', 'users', 'migrations'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
