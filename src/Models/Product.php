<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Product
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $description,
        public string $price,
        public int $quantityAvailable,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['name'],
            (string) $row['slug'],
            $row['description'] === null ? null : (string) $row['description'],
            (string) $row['price'],
            (int) $row['quantity_available'],
            (bool) $row['is_active'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
