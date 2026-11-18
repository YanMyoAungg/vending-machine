<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Models\PaginatedResult;
use App\Models\User;
use PDO;
use RuntimeException;

final class PdoUserRepository implements UserRepositoryInterface
{
    private const COLUMNS = 'id, name, email, password_hash, role, is_active, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function paginate(int $page, int $perPage, string $search = ''): PaginatedResult
    {
        $page = min(1_000_000, max(1, $page));
        $perPage = min(50, max(1, $perPage));
        $search = trim($search);

        $where = '';
        $params = [];

        if ($search !== '') {
            $where = ' WHERE (name LIKE :search_name OR email LIKE :search_email)';
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $params['search_name'] = $like;
            $params['search_email'] = $like;
        }

        $pdo = $this->connection->pdo();

        $count = $pdo->prepare('SELECT COUNT(*) FROM users' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $statement = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users' . $where
            . ' ORDER BY name ASC, id ASC LIMIT :limit OFFSET :offset'
        );

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        $items = array_map(
            static fn (array $row): User => User::fromRow($row),
            $statement->fetchAll()
        );

        return new PaginatedResult($items, $total, $page, $perPage);
    }

    public function findById(int $id): ?User
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $id]);

        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE email = :email'
        );
        $statement->execute(['email' => strtolower(trim($email))]);

        $row = $statement->fetch();

        return $row === false ? null : User::fromRow($row);
    }

    public function create(array $data): User
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active)
             VALUES (:name, :email, :password_hash, :role, :is_active)'
        );

        $statement->execute([
            'name' => $data['name'],
            'email' => strtolower(trim((string) $data['email'])),
            'password_hash' => $data['password_hash'],
            'role' => $data['role'],
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ]);

        return $this->findById((int) $this->connection->pdo()->lastInsertId());
    }

    public function update(int $id, array $data): User
    {
        $fields = [
            'name = :name',
            'email = :email',
            'role = :role',
            'is_active = :is_active',
        ];

        $params = [
            'id' => $id,
            'name' => $data['name'],
            'email' => strtolower(trim((string) $data['email'])),
            'role' => $data['role'],
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if (array_key_exists('password_hash', $data) && $data['password_hash'] !== null) {
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = $data['password_hash'];
        }

        $statement = $this->connection->pdo()->prepare(
            'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $statement->execute($params);

        $user = $this->findById($id);

        if ($user === null) {
            throw new RuntimeException("User {$id} no longer exists");
        }

        return $user;
    }

    public function softDelete(int $id): void
    {
        $statement = $this->connection->pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function restore(int $id): void
    {
        $statement = $this->connection->pdo()->prepare('UPDATE users SET is_active = 1 WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function countActiveAdmins(): int
    {
        return (int) $this->connection->pdo()
            ->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")
            ->fetchColumn();
    }

    public function emailExists(string $email, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM users WHERE email = :email';
        $params = ['email' => strtolower(trim($email))];

        if ($ignoreId !== null) {
            $sql .= ' AND id != :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }
}
