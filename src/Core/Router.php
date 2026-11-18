<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<RouteDefinition> */
    private array $definitions = [];

    /** @var array<string, callable(Container, ?string): MiddlewareInterface> */
    private array $middlewareFactories = [];

    /** @var list<MiddlewareInterface> */
    private array $globalMiddleware = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function addController(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $routes = $method->getAttributes(Route::class);

            if ($routes === []) {
                continue;
            }

            $middleware = array_map(
                static fn (\ReflectionAttribute $attribute) => $attribute->newInstance()->spec,
                $method->getAttributes(Middleware::class)
            );

            foreach ($routes as $attribute) {
                $route = $attribute->newInstance();

                $this->definitions[] = new RouteDefinition(
                    strtoupper($route->method),
                    $route->path,
                    $route->name,
                    $middleware,
                    $class,
                    $method->getName()
                );
            }
        }
    }

    /**
     * @param callable(Container, ?string): MiddlewareInterface $factory
     */
    public function middleware(string $name, callable $factory): void
    {
        $this->middlewareFactories[$name] = $factory;
    }

    public function useGlobal(MiddlewareInterface $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->definitions as $definition) {
            $parameters = $this->matchPath($definition, $request->path);

            if ($parameters === null) {
                continue;
            }

            if ($definition->method !== $request->method) {
                $allowed[] = $definition->method;
                continue;
            }

            $middleware = $this->globalMiddleware;

            foreach ($definition->middleware as $spec) {
                $middleware[] = $this->resolveMiddleware($spec);
            }

            $handler = fn (Request $current): Response => $this->invoke($definition, $current, $parameters);

            foreach (array_reverse($middleware) as $item) {
                $next = $handler;
                $handler = fn (Request $current): Response => $item->handle($current, $next);
            }

            return $handler($request);
        }

        if ($allowed !== []) {
            throw new HttpException(
                405,
                'Method not allowed.',
                ['Allow' => implode(', ', array_values(array_unique($allowed)))]
            );
        }

        throw new HttpException(404, 'Not found.');
    }

    public function url(string $name, array $params = []): string
    {
        foreach ($this->definitions as $definition) {
            if ($definition->name !== $name) {
                continue;
            }

            $path = preg_replace_callback(
                '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
                static function (array $matches) use (&$params): string {
                    if (!array_key_exists($matches[1], $params)) {
                        throw new \RuntimeException("Missing route parameter {$matches[1]}");
                    }

                    $value = $params[$matches[1]];
                    unset($params[$matches[1]]);

                    return rawurlencode((string) $value);
                },
                $definition->path
            );

            $path = $path === null ? $definition->path : $path;

            if ($params !== []) {
                $path .= '?' . http_build_query($params);
            }

            return $path;
        }

        throw new \RuntimeException("Unknown route {$name}");
    }

    /** @return list<RouteDefinition> */
    public function routes(): array
    {
        return $this->definitions;
    }

    /**
     * @return array<string, string>|null
     */
    private function matchPath(RouteDefinition $definition, string $path): ?array
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            static function (array $matches): string {
                $inner = $matches[2] ?? '[^/]+';

                return '(?P<' . $matches[1] . '>' . $inner . ')';
            },
            $definition->path
        );

        $pattern = $pattern === null ? $definition->path : $pattern;

        if (preg_match('#^' . $pattern . '$#', $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    private function resolveMiddleware(string $spec): MiddlewareInterface
    {
        [$name, $argument] = array_pad(explode(':', $spec, 2), 2, null);

        $factory = $this->middlewareFactories[$name] ?? null;

        if ($factory === null) {
            throw new \RuntimeException("Unknown middleware {$name}");
        }

        return $factory($this->container, $argument);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function invoke(RouteDefinition $definition, Request $request, array $parameters): Response
    {
        $method = new \ReflectionMethod($definition->controller, $definition->action);
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && $type->getName() === Request::class) {
                $arguments[] = $request;
                continue;
            }

            if (array_key_exists($parameter->getName(), $parameters)) {
                $arguments[] = $this->cast($parameters[$parameter->getName()], $type);
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(
                "Cannot resolve parameter \${$parameter->getName()} for {$definition->controller}::{$definition->action}"
            );
        }

        $controller = $this->container->get($definition->controller);
        $response = $controller->{$definition->action}(...$arguments);

        if (!$response instanceof Response) {
            throw new \RuntimeException('Controller actions must return a Response');
        }

        return $response;
    }

    private function cast(string $value, ?\ReflectionType $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }

        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
