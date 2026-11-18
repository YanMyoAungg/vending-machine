<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Container;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

interface UnresolvableDependency
{
}

final class ContainerFixtureConsumer
{
    public function __construct(public readonly ArrayObject $items)
    {
    }
}

final class ContainerFixtureMissing
{
    public function __construct(public readonly UnresolvableDependency $dependency)
    {
    }
}

final class ContainerTest extends TestCase
{
    public function test_resolves_registered_singleton(): void
    {
        $container = new Container();
        $container->set('answer', fn () => new ArrayObject([42]));

        self::assertSame($container->get('answer'), $container->get('answer'));
    }

    public function test_autowires_constructor_dependencies(): void
    {
        $container = new Container();
        $container->set(ArrayObject::class, fn () => new ArrayObject([1]));

        $resolved = $container->get(ContainerFixtureConsumer::class);

        self::assertInstanceOf(ArrayObject::class, $resolved->items);
        self::assertSame([1], $resolved->items->getArrayCopy());
    }

    public function test_throws_when_a_dependency_cannot_be_resolved(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot resolve');

        (new Container())->get(ContainerFixtureMissing::class);
    }
}
