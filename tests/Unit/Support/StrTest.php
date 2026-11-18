<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function test_slugifies_product_names(): void
    {
        self::assertSame('diet-coke-330ml', Str::slug('Diet Coke — 330ml'));
        self::assertSame('coke', Str::slug('  Coke  '));
        self::assertSame('water', Str::slug('Water!!!'));
    }
}
