<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\PaginatedResult;
use App\Models\Transaction;
use PDO;
use RuntimeException;

final class PdoTransactionRepository implements TransactionRepositoryInterface
{
    private const COLUMNS = 't.id, t.user_id, t.product_id, t.product_name, t.unit_price, t.quantity, t.total_price, t.created_at, u.name AS buyer_name';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(array $data): Transaction
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO transactions (user_id, product_id, product_name, unit_price, quantity, total_price)
             VALUES (:user_id, :product_id, :product_name, :unit_price, :quantity, :total_price)'
        );

        $statement->execute([
            'user_id' => $data['user_id'],
            'product_id' => $data['product_id'],
            'product_name' => $data['product_name'],
            'unit_price' => $data['unit_price'],
            'quantity' => $data['quantity'],
            'total_price' => $data['total_price'],
        ]);

        $transaction = $this->findById((int) $this->connection->pdo()->lastInsertId());

        if ($transaction === null) {
            throw new RuntimeException('Transaction could not be read back');
        }

        return $transaction;
    }

    public function findById(int $id): ?Transaction
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM transactions t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.id = :id'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : Transaction::fromRow($row);
    }

    public function paginate(int $page, int $perPage, ?int $userId = null): PaginatedResult
    {
        $page = min(1_000_000, max(1, $page));
        $perPage = min(100, max(1, $perPage));

        $where = '';
        $params = [];

        if ($userId !== null) {
            $where = ' WHERE t.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $pdo = $this->connection->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM transactions t' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $statement = $pdo->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM transactions t
             INNER JOIN users u ON u.id = t.user_id'
            . $where
            . ' ORDER BY t.created_at DESC, t.id DESC LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(
            static fn (array $row): Transaction => Transaction::fromRow($row),
            $statement->fetchAll()
        );

        return new PaginatedResult($items, $total, $page, $perPage);
    }
}
