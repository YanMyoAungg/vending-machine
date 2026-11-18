<?php

declare(strict_types=1);

namespace App\Core;

final readonly class RouteDefinition
{
    /**
     * @param list<string> $middleware
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $name,
        public array $middleware,
        public string $controller,
        public string $action,
    ) {
    }
}
