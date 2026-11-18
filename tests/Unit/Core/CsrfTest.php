<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\ArraySession;
use App\Core\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function test_rejects_wrong_token(): void
    {
        $csrf = new Csrf(new ArraySession());

        self::assertFalse($csrf->check('wrong'));
        self::assertTrue($csrf->check($csrf->token()));
    }

    public function test_token_is_stable_within_a_session(): void
    {
        $csrf = new Csrf(new ArraySession());

        self::assertSame($csrf->token(), $csrf->token());
        self::assertSame(64, strlen($csrf->token()));
    }

    public function test_field_contains_the_token(): void
    {
        $csrf = new Csrf(new ArraySession());

        $field = $csrf->field();

        self::assertStringContainsString('name="_token"', $field);
        self::assertStringContainsString($csrf->token(), $field);
    }
}
