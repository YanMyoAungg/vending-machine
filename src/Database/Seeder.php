<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Seeder
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(): void
    {
        $pdo = $this->connection->pdo();

        $products = [
            ['Coke', 'coke', 'Classic cola, chilled.', '3.990', 12],
            ['Pepsi', 'pepsi', 'Bright, sweet cola.', '6.885', 8],
            ['Water', 'water', 'Still mineral water.', '0.500', 24],
        ];

        $productStatement = $pdo->prepare(
            'INSERT IGNORE INTO products (name, slug, description, price, quantity_available, is_active)
             VALUES (:name, :slug, :description, :price, :quantity_available, 1)'
        );

        foreach ($products as [$name, $slug, $description, $price, $quantity]) {
            $productStatement->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'price' => $price,
                'quantity_available' => $quantity,
            ]);
        }

        $users = [
            ['Admin', 'admin@vending.test', 'admin'],
            ['Demo User', 'user@vending.test', 'user'],
        ];

        $userStatement = $pdo->prepare(
            'INSERT IGNORE INTO users (name, email, password_hash, role, is_active)
             VALUES (:name, :email, :password_hash, :role, 1)'
        );

        foreach ($users as [$name, $email, $role]) {
            $userStatement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash('password', PASSWORD_DEFAULT),
                'role' => $role,
            ]);
        }
    }
}
