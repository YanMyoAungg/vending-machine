<?php

declare(strict_types=1);

namespace App\Support;

final class Rules
{
    /** @return array<string, list<string>> */
    public static function product(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'decimal:3', 'min:0.001', 'max:9999.999'],
            'quantity_available' => ['required', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function purchase(): array
    {
        return ['quantity' => ['required', 'integer', 'min:1', 'max:1000']];
    }

    /** @return array<string, list<string>> */
    public static function userCreate(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', 'in:admin,user'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function userUpdate(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', 'in:admin,user'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
