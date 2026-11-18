<?php

declare(strict_types=1);

namespace App\Core;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    public function __construct(public readonly string $spec)
    {
    }
}
