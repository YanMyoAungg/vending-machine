<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Migrator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationsPath,
    ) {
    }

    /**
     * @return list<string> applied migration filenames
     */
    public function run(): array
    {
        $pdo = $this->connection->pdo();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                filename VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        $files = glob($this->migrationsPath . '/*.sql') ?: [];
        sort($files);

        $ran = [];

        foreach ($files as $file) {
            $filename = basename($file);

            if (in_array($filename, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new \RuntimeException("Cannot read migration {$filename}");
            }

            // MySQL DDL statements commit implicitly; do not wrap them in a transaction.
            $pdo->exec($sql);

            $statement = $pdo->prepare('INSERT INTO migrations (filename) VALUES (:filename)');
            $statement->execute(['filename' => $filename]);

            $ran[] = $filename;
        }

        return $ran;
    }
}
