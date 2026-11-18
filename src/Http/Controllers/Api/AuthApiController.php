<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\JwtService;

final class AuthApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly JwtService $jwt,
        private readonly Validator $validator,
    ) {
    }

    #[Route('POST', '/api/v1/auth/token', name: 'api.auth.token')]
    public function token(Request $request): Response
    {
        try {
            $validated = $this->validator->validate($request->body, [
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]);
        } catch (ValidationException $exception) {
            return Response::error(
                'validation_failed',
                'The given data was invalid.',
                422,
                $exception->errors()
            );
        }

        $user = $this->auth->verifyCredentials($validated['email'], $validated['password']);

        if ($user === null) {
            return Response::error('invalid_credentials', 'Invalid email or password.', 401);
        }

        return Response::json(['data' => $this->jwt->issue($user)]);
    }
}
