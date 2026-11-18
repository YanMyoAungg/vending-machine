<?php

declare(strict_types=1);

namespace App\Core;

interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function destroy(): void;

    public function flash(string $key, mixed $value): void;

    public function pullFlash(string $key, mixed $default = null): mixed;
}
