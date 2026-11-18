<?php

declare(strict_types=1);

namespace App\Database;

interface TransactionManagerInterface
{
    public function transactional(callable $callback): mixed;
}
