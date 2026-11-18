<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database\Seeder;

final class SeederTest extends IntegrationTestCase
{
    public function test_seed_is_idempotent_and_hashes_passwords(): void
    {
        $seeder = new Seeder($this->db);
        $seeder->run();
        $seeder->run();

        $pdo = $this->db->pdo();

        self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());

        $hash = (string) $pdo->query("SELECT password_hash FROM users WHERE email = 'admin@vending.test'")->fetchColumn();
        self::assertTrue(password_verify('password', $hash));

        $role = $pdo->query("SELECT role FROM users WHERE email = 'admin@vending.test'")->fetchColumn();
        self::assertSame('admin', $role);
    }

    public function test_reseeding_does_not_overwrite_live_stock_or_credentials(): void
    {
        $seeder = new Seeder($this->db);
        $seeder->run();

        $pdo = $this->db->pdo();
        $pdo->exec("UPDATE products SET quantity_available = 1, is_active = 0 WHERE slug = 'pepsi'");
        $pdo->exec("UPDATE users SET password_hash = 'changed', role = 'user', is_active = 0 WHERE email = 'admin@vending.test'");

        $seeder->run();

        $product = $pdo->query("SELECT quantity_available, is_active FROM products WHERE slug = 'pepsi'")->fetch();
        self::assertSame(1, (int) $product['quantity_available']);
        self::assertSame(0, (int) $product['is_active']);

        $admin = $pdo->query("SELECT password_hash, role, is_active FROM users WHERE email = 'admin@vending.test'")->fetch();
        self::assertSame('changed', $admin['password_hash']);
        self::assertSame('user', $admin['role']);
        self::assertSame(0, (int) $admin['is_active']);
    }

    public function test_seed_contains_the_required_products_and_prices(): void
    {
        (new Seeder($this->db))->run();

        $rows = $this->db->pdo()
            ->query('SELECT name, price, quantity_available FROM products ORDER BY name')
            ->fetchAll();

        self::assertSame(
            [
                ['name' => 'Coke', 'price' => '3.990', 'quantity_available' => 12],
                ['name' => 'Pepsi', 'price' => '6.885', 'quantity_available' => 8],
                ['name' => 'Water', 'price' => '0.500', 'quantity_available' => 24],
            ],
            array_map(
                static fn (array $row): array => [
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'quantity_available' => (int) $row['quantity_available'],
                ],
                $rows
            )
        );
    }
}
