<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Core\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;

final class RecordingMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    public static array $calls = [];

    public function __construct(private readonly string $name)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        self::$calls[] = $this->name;

        return $next($request);
    }
}
