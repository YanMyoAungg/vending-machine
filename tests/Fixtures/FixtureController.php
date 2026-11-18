<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\ValidationException;
use RuntimeException;

final class FixtureController
{
    #[Route('GET', '/greet/{name}', name: 'fixture.greet')]
    public function greet(string $name): Response
    {
        return Response::html('Hello ' . $name);
    }

    #[Route('GET', '/item/{id:\d+}', name: 'fixture.item')]
    public function item(int $id): Response
    {
        return Response::html('Item ' . $id);
    }

    #[Route('GET', '/guarded', name: 'fixture.guarded')]
    #[Middleware('first')]
    #[Middleware('second')]
    public function guarded(): Response
    {
        return Response::html('guarded');
    }

    #[Route('GET', '/invalid', name: 'fixture.invalid')]
    public function invalid(): Response
    {
        throw new ValidationException(['quantity' => ['Too many.']]);
    }

    #[Route('GET', '/boom', name: 'fixture.boom')]
    public function boom(): Response
    {
        throw new RuntimeException('secret detail');
    }

    #[Route('GET', '/missing', name: 'fixture.missing')]
    public function missing(): Response
    {
        throw new HttpException(404, 'Not here.');
    }
}
