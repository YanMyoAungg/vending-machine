<?php

declare(strict_types=1);

/**
 * Escape a value for HTML output.
 */
function escape(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Format a stored decimal price for display.
 */
function money(string $amount): string
{
    return 'USD ' . \App\Support\Money::format($amount);
}
