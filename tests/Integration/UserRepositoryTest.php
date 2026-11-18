<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Models\User;
use App\Repositories\PdoUserRepository;

final class UserRepositoryTest extends IntegrationTestCase
{
    private PdoUserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new PdoUserRepository($this->db);
    }

    public function test_creates_and_finds_user_by_lowercased_email(): void
    {
        $user = $this->repo->create([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password_hash' => 'hash',
            'role' => User::ROLE_USER,
            'is_active' => 1,
        ]);

        self::assertSame('ada@example.test', $this->repo->findByEmail('ADA@example.test')?->email);
        self::assertTrue($this->repo->findById($user->id)->isActive);
    }

    public function test_update_preserves_the_hash_when_password_is_omitted(): void
    {
        $user = $this->createUser('grace@test', User::ROLE_USER);

        $updated = $this->repo->update($user->id, [
            'name' => 'Grace Hopper',
            'email' => 'grace@test',
            'role' => User::ROLE_ADMIN,
            'is_active' => 1,
        ]);

        self::assertSame('Grace Hopper', $updated->name);
        self::assertSame(User::ROLE_ADMIN, $updated->role);
        self::assertSame('hash-grace@test', $updated->passwordHash);
    }

    public function test_update_can_replace_the_password_hash(): void
    {
        $user = $this->createUser('linus@test', User::ROLE_USER);

        $updated = $this->repo->update($user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => 1,
            'password_hash' => 'new-hash',
        ]);

        self::assertSame('new-hash', $updated->passwordHash);
    }

    public function test_last_admin_count_ignores_inactive_admins(): void
    {
        $this->createUser('a@test', User::ROLE_ADMIN, active: 1);
        $this->createUser('b@test', User::ROLE_ADMIN, active: 0);
        $this->createUser('c@test', User::ROLE_USER, active: 1);

        self::assertSame(1, $this->repo->countActiveAdmins());
    }

    public function test_soft_delete_keeps_the_row_but_marks_it_inactive(): void
    {
        $user = $this->createUser('gone@test', User::ROLE_USER);

        $this->repo->softDelete($user->id);

        self::assertFalse($this->repo->findById($user->id)->isActive);
        self::assertNotNull($this->repo->findByEmail('gone@test'));

        $page = $this->repo->paginate(1, 20);
        self::assertSame(1, $page->total);
        self::assertFalse($page->items[0]->isActive);

        $this->repo->restore($user->id);
        self::assertTrue($this->repo->findById($user->id)->isActive);
    }

    public function test_paginates_and_searches_users(): void
    {
        $this->createUser('ada@test', User::ROLE_USER, name: 'Ada Lovelace');
        $this->createUser('grace@test', User::ROLE_USER, name: 'Grace Hopper');
        $this->createUser('alan@test', User::ROLE_ADMIN, name: 'Alan Turing');

        self::assertSame(3, $this->repo->paginate(1, 2)->total);
        self::assertCount(2, $this->repo->paginate(1, 2)->items);
        self::assertSame(1, $this->repo->paginate(1, 20, 'hopper')->total);
    }

    public function test_email_exists_can_ignore_the_edited_user(): void
    {
        $user = $this->createUser('ada@test', User::ROLE_USER);

        self::assertTrue($this->repo->emailExists('ada@test'));
        self::assertTrue($this->repo->emailExists('ada@test', $user->id + 999));
        self::assertFalse($this->repo->emailExists('ada@test', $user->id));
    }

    public function test_database_rejects_duplicate_email(): void
    {
        $this->createUser('same@test', User::ROLE_USER);

        $this->expectException(\PDOException::class);

        $this->createUser('same@test', User::ROLE_USER);
    }

    private function createUser(
        string $email,
        string $role,
        int $active = 1,
        string $name = 'Test User'
    ): User {
        return $this->repo->create([
            'name' => $name,
            'email' => $email,
            'password_hash' => 'hash-' . $email,
            'role' => $role,
            'is_active' => $active,
        ]);
    }
}
