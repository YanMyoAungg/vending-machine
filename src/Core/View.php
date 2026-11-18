<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(
        private readonly string $basePath,
        private readonly Router $router,
        private readonly SessionInterface $session,
        private readonly Csrf $csrf,
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key): mixed
    {
        return $this->shared[$key] ?? null;
    }

    public function render(string $template, array $data = [], int $status = 200): Response
    {
        $data = array_merge(
            ['router' => $this->router, 'session' => $this->session, 'csrf' => $this->csrf],
            $this->shared,
            $data
        );

        $content = $this->capture($this->basePath . '/' . $template . '.php', $data);
        $body = $this->capture(
            $this->basePath . '/layouts/app.php',
            array_merge($data, ['content' => $content])
        );

        return Response::html($body, $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);

        ob_start();

        try {
            require $__file;
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }
}
