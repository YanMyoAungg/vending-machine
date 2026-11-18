<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database\Connection;
use App\Database\Migrator;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected Connection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Connection::fromEnv();

        (new Migrator($this->db, $this->migrationsPath()))->run();

        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['transactions', 'products', 'users'] as $table) {
            $pdo->exec("TRUNCATE TABLE {$table}");
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function migrationsPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }
}
