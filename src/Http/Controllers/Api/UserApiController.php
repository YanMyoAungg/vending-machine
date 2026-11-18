<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\PasswordHasherInterface;
use App\Services\UserPolicy;
use App\Support\Rules;

final class UserApiController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly UserPolicy $policy,
        private readonly Validator $validator,
    ) {
    }

    #[Route('GET', '/api/v1/users', name: 'api.users.index')]
    #[Middleware('api_auth')]
    #[Middleware('role:admin')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) ($request->queryString('page', '1')));
        $perPage = (int) ($request->queryString('per_page', '20'));
        $search = trim($request->queryString('search', '') ?? '');

        $paginator = $this->users->paginate($page, $perPage, $search);

        return Response::json([
            'data' => array_map($this->toArray(...), $paginator->items),
            'meta' => [
                'page' => $paginator->page,
                'per_page' => $paginator->perPage,
                'total' => $paginator->total,
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    #[Route('POST', '/api/v1/users', name: 'api.users.store')]
    #[Middleware('api_auth')]
    #[Middleware('role:admin')]
    public function store(Request $request): Response
    {
        $data = [
            'name' => (string) ($request->input('name') ?? ''),
            'email' => mb_strtolower(trim((string) ($request->input('email') ?? ''))),
            'role' => (string) ($request->input('role') ?? User::ROLE_USER),
            'password' => (string) ($request->input('password') ?? ''),
            'is_active' => $this->boolean($request->input('is_active'), true),
        ];

        try {
            $validated = $this->validator->validate($data, Rules::userCreate());

            if ($this->users->emailExists($validated['email'])) {
                throw new ValidationException(['email' => ['That email is already registered.']]);
            }

            $user = $this->users->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password_hash' => $this->hasher->hash($validated['password']),
                'role' => $validated['role'],
                'is_active' => (bool) $validated['is_active'],
            ]);
        } catch (ValidationException $exception) {
            return Response::error('validation_failed', 'The given data was invalid.', 422, $exception->errors());
        }

        return Response::json(['data' => $this->toArray($user)], 201);
    }

    #[Route('PATCH', '/api/v1/users/{id:\d+}', name: 'api.users.update')]
    #[Middleware('api_auth')]
    #[Middleware('role:admin')]
    public function update(int $id, Request $request): Response
    {
        $target = $this->findUser($id);
        $actor = $this->actor($request);

        $data = [
            'name' => (string) ($request->input('name') ?? $target->name),
            'email' => mb_strtolower(trim((string) ($request->input('email') ?? $target->email))),
            'role' => (string) ($request->input('role') ?? $target->role),
            'password' => (string) ($request->input('password') ?? ''),
            'is_active' => $this->boolean($request->input('is_active'), $target->isActive),
        ];

        try {
            $validated = $this->validator->validate($data, Rules::userUpdate());

            if ($this->users->emailExists($validated['email'], $target->id)) {
                throw new ValidationException(['email' => ['That email is already registered.']]);
            }

            $newActive = (bool) $validated['is_active'];
            $this->policy->assertCanChange($actor, $target, $validated['role'], $newActive);

            $update = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'is_active' => $newActive,
            ];

            if (($validated['password'] ?? '') !== '') {
                $update['password_hash'] = $this->hasher->hash($validated['password']);
            }

            $user = $this->users->update($target->id, $update);
        } catch (ValidationException $exception) {
            return Response::error('validation_failed', 'The given data was invalid.', 422, $exception->errors());
        }

        return Response::json(['data' => $this->toArray($user)]);
    }

    #[Route('DELETE', '/api/v1/users/{id:\d+}', name: 'api.users.destroy')]
    #[Middleware('api_auth')]
    #[Middleware('role:admin')]
    public function destroy(int $id, Request $request): Response
    {
        $target = $this->findUser($id);

        try {
            $this->policy->assertCanChange($this->actor($request), $target, $target->role, false);
        } catch (ValidationException $exception) {
            return Response::error('validation_failed', 'The given data was invalid.', 422, $exception->errors());
        }

        $this->users->softDelete($target->id);

        return Response::noContent();
    }

    private function findUser(int $id): User
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            throw new HttpException(404, 'User not found.');
        }

        return $user;
    }

    private function actor(Request $request): User
    {
        $user = $request->attribute('user');

        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->isActive,
            'created_at' => $user->createdAt,
        ];
    }

    private function boolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
