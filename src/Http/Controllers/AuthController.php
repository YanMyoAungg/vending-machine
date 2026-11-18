<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\Router;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Services\AuthService;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly View $view,
        private readonly Router $router,
        private readonly Validator $validator,
    ) {
    }

    #[Route('GET', '/login', name: 'login')]
    public function showLogin(Request $request): Response
    {
        return $this->view->render('auth/login', [
            'errors' => [],
            'old' => [],
            'next' => $this->safeNext((string) ($request->query('next') ?? '')),
        ]);
    }

    #[Route('POST', '/login', name: 'login.store')]
    #[Middleware('csrf')]
    public function login(Request $request): Response
    {
        try {
            $this->validator->validate($request->body, [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);
        } catch (ValidationException $exception) {
            return $this->view->render('auth/login', [
                'errors' => $exception->errors(),
                'old' => $request->body,
                'next' => $this->safeNext((string) ($request->input('next') ?? '')),
            ], 422);
        }

        $email = (string) $request->input('email');
        $password = (string) $request->input('password');

        if (!$this->auth->attempt($email, $password)) {
            return $this->view->render('auth/login', [
                'errors' => ['email' => ['Invalid email or password.']],
                'old' => ['email' => $email],
                'next' => $this->safeNext((string) ($request->input('next') ?? '')),
            ], 422);
        }

        $next = $this->safeNext((string) ($request->input('next') ?? ''));

        return Response::redirect($next === '' ? '/products' : $next);
    }

    #[Route('POST', '/logout', name: 'logout')]
    #[Middleware('auth')]
    #[Middleware('csrf')]
    public function logout(): Response
    {
        $this->auth->logout();

        return Response::redirect('/products');
    }

    private function safeNext(string $next): string
    {
        if ($next === ''
            || !str_starts_with($next, '/')
            || str_starts_with($next, '//')
            || str_contains($next, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $next) === 1
        ) {
            return '/products';
        }

        return $next;
    }
}
