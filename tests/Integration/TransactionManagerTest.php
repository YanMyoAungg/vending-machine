<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Database\PdoTransactionManager;
use RuntimeException;

final class TransactionManagerTest extends IntegrationTestCase
{
    public function test_commits_a_successful_callback(): void
    {
        $manager = new PdoTransactionManager($this->db);

        $result = $manager->transactional(function (): string {
            $this->db->pdo()->exec(
                "INSERT INTO users (name, email, password_hash, role)
                 VALUES ('Committed', 'committed@test', 'hash', 'user')"
            );

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame(
            1,
            (int) $this->db->pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'committed@test'")->fetchColumn()
        );
    }

    public function test_rolls_back_when_the_callback_throws(): void
    {
        $manager = new PdoTransactionManager($this->db);

        try {
            $manager->transactional(function (): void {
                $this->db->pdo()->exec(
                    "INSERT INTO users (name, email, password_hash, role)
                     VALUES ('Rolled', 'rolled@test', 'hash', 'user')"
                );

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        self::assertSame(
            0,
            (int) $this->db->pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'rolled@test'")->fetchColumn()
        );
    }
}
