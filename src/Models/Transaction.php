<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Transaction
{
    public function __construct(
        public int $id,
        public int $userId,
        public int $productId,
        public string $productName,
        public string $unitPrice,
        public int $quantity,
        public string $totalPrice,
        public string $createdAt,
        public ?string $buyerName = null,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['product_id'],
            (string) $row['product_name'],
            (string) $row['unit_price'],
            (int) $row['quantity'],
            (string) $row['total_price'],
            (string) $row['created_at'],
            isset($row['buyer_name']) ? (string) $row['buyer_name'] : null,
        );
    }
}
