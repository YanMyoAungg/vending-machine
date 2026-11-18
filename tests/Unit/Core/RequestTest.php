<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_normalizes_method_and_reads_inputs(): void
    {
        $request = new Request(
            'post',
            '/products?x=1',
            ['x' => '1'],
            ['quantity' => '2'],
            ['Content-Type' => 'application/json']
        );

        self::assertSame('POST', $request->method);
        self::assertSame('/products', $request->path);
        self::assertSame('1', $request->query('x'));
        self::assertSame('2', $request->input('quantity'));
        self::assertTrue($request->isJson());
        self::assertSame('/other', $request->withAttribute('next', '/other')->attribute('next'));
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $request = new Request('GET', '/', headers: ['X-CSRF-Token' => 'abc']);

        self::assertSame('abc', $request->header('x-csrf-token'));
        self::assertSame('fallback', $request->header('missing', 'fallback'));
    }

    public function test_api_paths_expect_json_without_an_accept_header(): void
    {
        self::assertTrue((new Request('GET', '/api/v1/products'))->expectsJson());
        self::assertFalse((new Request('GET', '/products'))->expectsJson());
        self::assertTrue((new Request('GET', '/products', headers: ['Accept' => 'application/json']))->expectsJson());
    }

    public function test_input_falls_back_to_the_default(): void
    {
        $request = new Request('GET', '/');

        self::assertNull($request->input('missing'));
        self::assertSame('fallback', $request->input('missing', 'fallback'));
    }

    public function test_string_accessors_ignore_array_values(): void
    {
        $request = new Request('GET', '/products', ['sort' => ['x'], 'search' => 'pepsi']);

        self::assertNull($request->queryString('sort'));
        self::assertSame('name', $request->queryString('sort', 'name'));
        self::assertSame('pepsi', $request->queryString('search'));
        self::assertNull($request->inputString('name'));
    }
}
