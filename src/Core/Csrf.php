<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const SESSION_KEY = "_csrf";

    public function __construct(private readonly SessionInterface $session) {}

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === "") {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function check(?string $token): bool
    {
        if ($token === null || $token === "") {
            return false;
        }

        return hash_equals($this->token(), $token);
    }

    public function field(): string
    {
        return '<input type="hidden" name="_token" value="' .
            escape($this->token()) .
            '">';
    }
}
