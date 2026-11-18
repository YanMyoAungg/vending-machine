<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_multiplies_without_float_error(): void
    {
        self::assertSame('20.655', Money::multiply('6.885', 3));
        self::assertSame('13.770', Money::multiply('6.885', 2));
        self::assertSame('0.500', Money::multiply('0.500', 1));
    }

    public function test_formats_with_two_or_three_decimals(): void
    {
        self::assertSame('USD 20.655', money('20.655'));
        self::assertSame('USD 3.99', money('3.990'));
        self::assertSame('USD 0.50', money('0.500'));
        self::assertSame('USD 13.77', money('13.770'));
    }

    public function test_converts_to_and_from_mills(): void
    {
        self::assertSame(6885, Money::toMills('6.885'));
        self::assertSame(3990, Money::toMills('3.99'));
        self::assertSame('6.885', Money::fromMills(6885));
    }

    public function test_rejects_malformed_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMills('6.8855');
    }
}
