<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\SessionInterface;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;

final class AuthService
{
    private bool $loaded = false;

    private ?User $user = null;

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly SessionInterface $session,
    ) {
    }

    public function verifyCredentials(string $email, string $password): ?User
    {
        $user = $this->users->findByEmail(trim($email));

        if ($user === null || !$user->isActive) {
            return null;
        }

        if (!$this->hasher->verify($password, $user->passwordHash)) {
            return null;
        }

        return $user;
    }

    public function attempt(string $email, string $password): bool
    {
        $user = $this->verifyCredentials($email, $password);

        if ($user === null) {
            return false;
        }

        $this->session->regenerate();
        $this->session->set('auth_user_id', $user->id);

        $this->user = $user;
        $this->loaded = true;

        return true;
    }

    public function user(): ?User
    {
        if ($this->loaded) {
            return $this->user;
        }

        $this->loaded = true;
        $id = $this->session->get('auth_user_id');

        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }

        $user = $this->users->findById((int) $id);

        if ($user === null || !$user->isActive) {
            return null;
        }

        return $this->user = $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
        $this->loaded = true;
    }
}
