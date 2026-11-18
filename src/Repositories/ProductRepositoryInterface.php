<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PaginatedResult;
use App\Models\Product;

interface ProductRepositoryInterface
{
    /**
     * @param array{
     *     page?: int,
     *     per_page?: int,
     *     sort?: string,
     *     direction?: string,
     *     search?: string,
     *     include_inactive?: bool
     * } $options
     *
     * @return PaginatedResult<Product>
     */
    public function paginate(array $options = []): PaginatedResult;

    public function findById(int $id): ?Product;

    public function findBySlug(string $slug): ?Product;

    public function findBySlugForUpdate(string $slug): ?Product;

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Product;

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Product;

    public function softDelete(int $id): void;

    public function restore(int $id): void;

    public function decrementStock(int $id, int $quantity): void;

    public function slugExists(string $slug, ?int $ignoreId = null): bool;
}
