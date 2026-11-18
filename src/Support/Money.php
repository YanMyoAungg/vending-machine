<?php

declare(strict_types=1);

namespace App\Support;

final class Money
{
    public static function toMills(string $amount): int
    {
        $amount = trim($amount);

        if ($amount === '' || !preg_match('/^\d+(?:\.\d{1,3})?$/', $amount)) {
            throw new \InvalidArgumentException("Invalid money amount: {$amount}");
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole) * 1000 + (int) str_pad($fraction, 3, '0');
    }

    public static function fromMills(int $mills): string
    {
        $sign = $mills < 0 ? '-' : '';
        $mills = abs($mills);

        return $sign . intdiv($mills, 1000) . '.' . str_pad((string) ($mills % 1000), 3, '0', STR_PAD_LEFT);
    }

    public static function multiply(string $amount, int $quantity): string
    {
        if ($quantity < 0) {
            throw new \InvalidArgumentException('Quantity cannot be negative');
        }

        return self::fromMills(self::toMills($amount) * $quantity);
    }

    public static function format(string $amount): string
    {
        $formatted = self::fromMills(self::toMills($amount));

        while (substr_count($formatted, '.') === 1 && strlen(substr(strrchr($formatted, '.'), 1)) > 2 && str_ends_with($formatted, '0')) {
            $formatted = substr($formatted, 0, -1);
        }

        return $formatted;
    }
}
