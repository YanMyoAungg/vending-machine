<?php

declare(strict_types=1);

namespace App\Database;

use Throwable;

final class PdoTransactionManager implements TransactionManagerInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function transactional(callable $callback): mixed
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
