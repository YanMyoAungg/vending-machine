<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Api;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Http\Controllers\Api\ProductApiController;
use App\Models\PaginatedResult;
use App\Models\Product;
use App\Repositories\ProductRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ProductApiControllerTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $products;

    protected function setUp(): void
    {
        $this->products = $this->createMock(ProductRepositoryInterface::class);
    }

    public function test_index_returns_paginated_payload(): void
    {
        $this->products->method('paginate')->willReturn(new PaginatedResult([$this->product()], 3, 1, 2));

        $response = $this->controller()->index(new Request('GET', '/api/v1/products', ['per_page' => '2']));
        $json = json_decode($response->body(), true);

        self::assertSame(200, $response->status());
        self::assertSame(3, $json['meta']['total']);
        self::assertSame(2, $json['meta']['last_page']);
        self::assertSame('Coke', $json['data'][0]['name']);
        self::assertArrayNotHasKey('password_hash', $json['data'][0]);
    }

    public function test_index_accepts_the_spec_q_search_parameter(): void
    {
        $this->products->expects(self::once())->method('paginate')->with(self::callback(
            static fn (array $options): bool => ($options['search'] ?? null) === 'pepsi'
        ))->willReturn(new PaginatedResult([], 0, 1, 20));

        $this->controller()->index(new Request('GET', '/api/v1/products', ['q' => 'pepsi']));
    }

    public function test_show_returns_json_or_404(): void
    {
        $this->products->method('findBySlug')->willReturnCallback(
            fn (string $slug): ?Product => $slug === 'coke' ? $this->product() : null
        );

        $response = $this->controller()->show('coke');
        self::assertSame(200, $response->status());
        self::assertSame('coke', json_decode($response->body(), true)['data']['slug']);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->controller()->show('missing');
    }

    public function test_store_validates_and_derives_slug(): void
    {
        $this->products->method('slugExists')->willReturn(false);
        $this->products->expects(self::once())->method('create')->with(self::callback(
            static fn (array $data): bool => $data['slug'] === 'diet-coke' && $data['is_active'] === true
        ))->willReturn($this->product());

        $response = $this->controller()->store($this->jsonRequest([
            'name' => 'Diet Coke',
            'price' => '2.500',
            'quantity_available' => 4,
        ]));

        self::assertSame(201, $response->status());
        self::assertSame('Coke', json_decode($response->body(), true)['data']['name']);
    }

    public function test_store_returns_validation_details(): void
    {
        $response = $this->controller()->store($this->jsonRequest(['name' => '', 'price' => '0']));

        self::assertSame(422, $response->status());
        $payload = json_decode($response->body(), true);
        self::assertSame('validation_failed', $payload['error']['code']);
        self::assertArrayHasKey('price', $payload['error']['details']);
    }

    public function test_update_merges_partial_payloads(): void
    {
        $product = $this->product();
        $this->products->method('findById')->with(1)->willReturn($product);
        $this->products->method('slugExists')->with('coke', 1)->willReturn(false);
        $this->products->expects(self::once())->method('update')->with(1, self::callback(
            static fn (array $data): bool => $data['name'] === 'Coke'
                && $data['price'] === '4.500'
                && $data['quantity_available'] === 12
        ))->willReturn($product);

        $response = $this->controller()->update(1, $this->jsonRequest(['price' => '4.500']));

        self::assertSame(200, $response->status());
    }

    public function test_destroy_soft_deletes_and_returns_204(): void
    {
        $product = $this->product();
        $this->products->method('findById')->willReturn($product);
        $this->products->expects(self::once())->method('softDelete')->with(1);

        $response = $this->controller()->destroy(1);

        self::assertSame(204, $response->status());
        self::assertSame('', $response->body());
    }

    private function controller(): ProductApiController
    {
        return new ProductApiController($this->products, new Validator());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return new Request('POST', '/api/v1/products', body: $body, headers: ['Content-Type' => 'application/json']);
    }

    private function product(): Product
    {
        return new Product(
            1,
            'Coke',
            'coke',
            'Cola',
            '3.990',
            12,
            true,
            '2026-01-01 00:00:00',
            '2026-01-01 00:00:00'
        );
    }
}
