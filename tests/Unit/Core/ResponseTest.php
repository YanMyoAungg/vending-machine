<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_json_error_shape(): void
    {
        $response = Response::error('not_found', 'No such product.', 404);

        self::assertSame(404, $response->status());
        self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
        self::assertSame(
            ['error' => ['code' => 'not_found', 'message' => 'No such product.']],
            json_decode($response->body(), true)
        );
    }

    public function test_json_encodes_data_and_status(): void
    {
        $response = Response::json(['data' => ['name' => 'Coke']], 201);

        self::assertSame(201, $response->status());
        self::assertSame('Coke', json_decode($response->body(), true)['data']['name']);
    }

    public function test_redirect_sets_location_and_status(): void
    {
        $response = Response::redirect('/products');

        self::assertSame(302, $response->status());
        self::assertSame('/products', $response->headers()['Location']);
        self::assertSame('', $response->body());
    }

    public function test_with_header_returns_a_copy(): void
    {
        $original = Response::html('<p>Hello</p>', 200);
        $changed = $original->withHeader('X-Test', 'yes');

        self::assertArrayNotHasKey('X-Test', $original->headers());
        self::assertSame('yes', $changed->headers()['X-Test']);
        self::assertSame(200, $changed->status());
    }

    public function test_no_content_has_no_body(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
    }
}
