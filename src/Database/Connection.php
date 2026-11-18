<?php

declare(strict_types=1);

namespace App\Database;

use App\Core\Env;
use PDO;

final class Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromEnv(): self
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $database = Env::get('DB_DATABASE', 'vending');
        $username = Env::get('DB_USERNAME', 'vending');
        $password = Env::get('DB_PASSWORD', 'secret');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $database
        );

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
