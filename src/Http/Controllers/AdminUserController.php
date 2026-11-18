<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\Router;
use App\Core\SessionInterface;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Models\User;
use App\Repositories\UserRepositoryInterface;
use App\Services\PasswordHasherInterface;
use App\Services\UserPolicy;
use App\Support\Rules;

final class AdminUserController
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherInterface $hasher,
        private readonly UserPolicy $policy,
        private readonly View $view,
        private readonly Router $router,
        private readonly SessionInterface $session,
        private readonly Validator $validator,
    ) {
    }

    #[Route('GET', '/admin/users', name: 'admin.users.index')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) ($request->queryString('page', '1')));
        $search = trim($request->queryString('search', '') ?? '');

        return $this->view->render('admin/users/index', [
            'users' => $this->users->paginate($page, 20, $search),
            'query' => $request->query,
            'search' => $search,
        ]);
    }

    #[Route('GET', '/admin/users/create', name: 'admin.users.create')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    public function create(): Response
    {
        return $this->renderForm(null, [], []);
    }

    #[Route('POST', '/admin/users', name: 'admin.users.store')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    #[Middleware('csrf')]
    public function store(Request $request): Response
    {
        $data = $this->formData($request);

        try {
            $validated = $this->validator->validate($data, Rules::userCreate());

            if ($this->users->emailExists($validated['email'])) {
                throw new ValidationException(['email' => ['That email is already registered.']]);
            }

            $this->users->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password_hash' => $this->hasher->hash($validated['password']),
                'role' => $validated['role'],
                'is_active' => (bool) $validated['is_active'],
            ]);
        } catch (ValidationException $exception) {
            return $this->renderForm(null, $exception->errors(), $data, 422);
        }

        $this->session->flash('success', 'User created.');

        return Response::redirect($this->router->url('admin.users.index'));
    }

    #[Route('GET', '/admin/users/{id:\d+}/edit', name: 'admin.users.edit')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    public function edit(int $id): Response
    {
        return $this->renderForm($this->findUser($id), [], []);
    }

    #[Route('POST', '/admin/users/{id:\d+}', name: 'admin.users.update')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    #[Middleware('csrf')]
    public function update(int $id, Request $request): Response
    {
        $target = $this->findUser($id);
        $actor = $this->actor($request);
        $data = $this->formData($request, $target);

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

            $this->users->update($target->id, $update);
        } catch (ValidationException $exception) {
            return $this->renderForm($target, $exception->errors(), $data, 422);
        }

        $this->session->flash('success', 'User updated.');

        return Response::redirect($this->router->url('admin.users.index'));
    }

    #[Route('POST', '/admin/users/{id:\d+}/delete', name: 'admin.users.destroy')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    #[Middleware('csrf')]
    public function destroy(int $id, Request $request): Response
    {
        $target = $this->findUser($id);
        $newActive = !$target->isActive;

        try {
            $this->policy->assertCanChange($this->actor($request), $target, $target->role, $newActive);
        } catch (ValidationException $exception) {
            $this->session->flash('error', $exception->errors()['user'][0]);

            return Response::redirect($this->router->url('admin.users.index'));
        }

        if ($newActive) {
            $this->users->restore($target->id);
            $this->session->flash('success', 'User activated.');
        } else {
            $this->users->softDelete($target->id);
            $this->session->flash('success', 'User deactivated.');
        }

        return Response::redirect($this->router->url('admin.users.index'));
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
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $old
     */
    private function renderForm(?User $user, array $errors, array $old, int $status = 200): Response
    {
        return $this->view->render('admin/users/form', [
            'user' => $user,
            'errors' => $errors,
            'old' => $old,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, ?User $user = null): array
    {
        return [
            'name' => (string) ($request->input('name') ?? $user?->name ?? ''),
            'email' => mb_strtolower(trim((string) ($request->input('email') ?? $user?->email ?? ''))),
            'role' => (string) ($request->input('role') ?? $user?->role ?? User::ROLE_USER),
            'password' => (string) ($request->input('password') ?? ''),
            'is_active' => (int) filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
