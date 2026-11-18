<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTest extends TestCase
{
    public function test_loads_values_and_ignores_comments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "APP_NAME=\"Vending Machine\"\n# nope\nDB_PORT=3307\nEMPTY=\n");

        Env::load($path);

        self::assertSame('Vending Machine', Env::get('APP_NAME'));
        self::assertSame('3307', Env::get('DB_PORT'));
        self::assertSame('', Env::get('EMPTY'));

        unlink($path);
    }

    public function test_require_throws_for_missing_key(): void
    {
        $this->expectException(RuntimeException::class);

        Env::require('DEFINITELY_MISSING_KEY');
    }

    public function test_bool_parses_truthy_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, "FEATURE_ON=true\nFEATURE_OFF=false\n");

        Env::load($path);

        self::assertTrue(Env::bool('FEATURE_ON'));
        self::assertFalse(Env::bool('FEATURE_OFF'));

        unlink($path);
    }
}
