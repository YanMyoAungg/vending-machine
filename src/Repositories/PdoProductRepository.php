<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\PaginatedResult;
use App\Models\Product;
use PDO;
use RuntimeException;

final class PdoProductRepository implements ProductRepositoryInterface
{
    private const COLUMNS = 'id, name, slug, description, price, quantity_available, is_active, created_at, updated_at';

    private const SORTS = ['name', 'price', 'quantity_available', 'created_at'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function paginate(array $options = []): PaginatedResult
    {
        $page = min(1_000_000, max(1, (int) ($options['page'] ?? 1)));
        $perPage = min(50, max(1, (int) ($options['per_page'] ?? 9)));

        $sort = (string) ($options['sort'] ?? 'name');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'name';

        $direction = strtolower((string) ($options['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $search = trim((string) ($options['search'] ?? ''));
        $includeInactive = (bool) ($options['include_inactive'] ?? false);

        $where = [];
        $params = [];

        if (!$includeInactive) {
            $where[] = 'is_active = 1';
        }

        if ($search !== '') {
            $where[] = '(name LIKE :search_name OR slug LIKE :search_slug)';
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $params['search_name'] = $like;
            $params['search_slug'] = $like;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $pdo = $this->connection->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM products' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT ' . self::COLUMNS . ' FROM products' . $whereSql
            . ' ORDER BY ' . $sort . ' ' . $direction . ', id ASC'
            . ' LIMIT :limit OFFSET :offset';

        $statement = $pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(
            static fn (array $row): Product => Product::fromRow($row),
            $statement->fetchAll()
        );

        return new PaginatedResult($items, $total, $page, $perPage);
    }

    public function findById(int $id): ?Product
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM products WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : Product::fromRow($row);
    }

    public function findBySlug(string $slug): ?Product
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM products WHERE slug = :slug AND is_active = 1'
        );
        $statement->execute(['slug' => $slug]);

        $row = $statement->fetch();

        return $row === false ? null : Product::fromRow($row);
    }

    public function findBySlugForUpdate(string $slug): ?Product
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM products WHERE slug = :slug AND is_active = 1 FOR UPDATE'
        );
        $statement->execute(['slug' => $slug]);

        $row = $statement->fetch();

        return $row === false ? null : Product::fromRow($row);
    }

    public function create(array $data): Product
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO products (name, slug, description, price, quantity_available, is_active)
             VALUES (:name, :slug, :description, :price, :quantity_available, :is_active)'
        );

        $statement->execute([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'quantity_available' => (int) $data['quantity_available'],
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);

        return $this->findById((int) $this->connection->pdo()->lastInsertId());
    }

    public function update(int $id, array $data): Product
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE products
             SET name = :name,
                 slug = :slug,
                 description = :description,
                 price = :price,
                 quantity_available = :quantity_available,
                 is_active = :is_active
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'quantity_available' => (int) $data['quantity_available'],
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);

        $product = $this->findById($id);

        if ($product === null) {
            throw new RuntimeException("Product {$id} no longer exists");
        }

        return $product;
    }

    public function softDelete(int $id): void
    {
        $statement = $this->connection->pdo()->prepare('UPDATE products SET is_active = 0 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function restore(int $id): void
    {
        $statement = $this->connection->pdo()->prepare('UPDATE products SET is_active = 1 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function decrementStock(int $id, int $quantity): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE products
             SET quantity_available = quantity_available - :decrement
             WHERE id = :id AND quantity_available >= :minimum'
        );

        $statement->execute(['id' => $id, 'decrement' => $quantity, 'minimum' => $quantity]);

        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Insufficient stock');
        }
    }

    public function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM products WHERE slug = :slug';

        $params = ['slug' => $slug];

        if ($ignoreId !== null) {
            $sql .= ' AND id != :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }
}
