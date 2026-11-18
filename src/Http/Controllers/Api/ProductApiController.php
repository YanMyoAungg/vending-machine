<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Models\Product;
use App\Repositories\ProductRepositoryInterface;
use App\Support\Rules;
use App\Support\Str;

final class ProductApiController
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly Validator $validator,
    ) {
    }

    #[Route('GET', '/api/v1/products', name: 'api.products.index')]
    public function index(Request $request): Response
    {
        $paginator = $this->products->paginate([
            'page' => max(1, (int) ($request->queryString('page', '1'))),
            'per_page' => (int) ($request->queryString('per_page', '20')),
            'sort' => $request->queryString('sort', 'name') ?? 'name',
            'direction' => $request->queryString('direction', 'asc') ?? 'asc',
            'search' => $request->queryString('q') ?? $request->queryString('search', '') ?? '',
        ]);

        return Response::json([
            'data' => array_map($this->toArray(...), $paginator->items),
            'meta' => [
                'page' => $paginator->page,
                'per_page' => $paginator->perPage,
                'total' => $paginator->total,
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    #[Route('GET', '/api/v1/products/{slug}', name: 'api.products.show')]
    public function show(string $slug): Response
    {
        $product = $this->products->findBySlug($slug);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        return Response::json(['data' => $this->toArray($product)]);
    }

    #[Route('POST', '/api/v1/products', name: 'api.products.store')]
    #[Middleware('api_auth')]
    #[Middleware('role:admin')]
    public function store(Request $request): Response
    {
        $body = $request->body;
        $body['slug'] = (string) ($body['slug'] ?? '') !== ''
            ? (string) $body['slug']
            : Str::slug((string) ($body['name'] ?? ''));
        $body['is_active'] = $this->boolean($body['is_active'] ?? null, true);

        try {
            $validated = $this->validator->validate($body, Rules::product());

            if ($this->products->slugExists($validated['slug'])) {
                throw new ValidationException(['slug' => ['This slug is already in use.']]);
            }

            $product = $this->products->create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] !== '' ? $validated['description'] : null,
                'price' => $validated['price'],
                'quantity_available' => (int) $validated['quantity_available'],
                'is_active' => (bool) $validated['is_active'],
            ]);
        } catch (ValidationException $exception) {
            return Response::error('validation_failed', 'The given data was invalid.', 422, $exception->errors());
        }

        return Response::json(['data' => $this->toArray($product)], 201);
    }

    #[Route('PATCH', '/api/v1/products/{id:\d+}', name: 'api.products.update')]
    #[Middleware('api_auth')]
    #[Middleware('role:admin')]
    public function update(int $id, Request $request): Response
    {
        $product = $this->products->findById($id);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        $body = $request->body;
        $data = [
            'name' => (string) ($body['name'] ?? $product->name),
            'slug' => (string) ($body['slug'] ?? $product->slug),
            'description' => (string) ($body['description'] ?? $product->description ?? ''),
            'price' => (string) ($body['price'] ?? $product->price),
            'quantity_available' => (string) ($body['quantity_available'] ?? $product->quantityAvailable),
            'is_active' => $this->boolean($body['is_active'] ?? null, $product->isActive),
        ];

        if ($data['slug'] === '') {
            $data['slug'] = Str::slug($data['name']);
        }

        try {
            $validated = $this->validator->validate($data, Rules::product());

            if ($this->products->slugExists($validated['slug'], $product->id)) {
                throw new ValidationException(['slug' => ['This slug is already in use.']]);
            }

            $updated = $this->products->update($product->id, [
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] !== '' ? $validated['description'] : null,
                'price' => $validated['price'],
                'quantity_available' => (int) $validated['quantity_available'],
                'is_active' => (bool) $validated['is_active'],
            ]);
        } catch (ValidationException $exception) {
            return Response::error('validation_failed', 'The given data was invalid.', 422, $exception->errors());
        }

        return Response::json(['data' => $this->toArray($updated)]);
    }

    #[Route('DELETE', '/api/v1/products/{id:\d+}', name: 'api.products.destroy')]
    #[Middleware('api_auth')]
    #[Middleware('role:admin')]
    public function destroy(int $id): Response
    {
        $product = $this->products->findById($id);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        $this->products->softDelete($product->id);

        return Response::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'price' => $product->price,
            'quantity_available' => $product->quantityAvailable,
            'is_active' => $product->isActive,
            'created_at' => $product->createdAt,
            'updated_at' => $product->updatedAt,
        ];
    }

    private function boolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }
}
