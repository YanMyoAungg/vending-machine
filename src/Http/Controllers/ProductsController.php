<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\Router;
use App\Core\SessionInterface;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Core\View;
use App\Database\TransactionManagerInterface;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\ProductRepositoryInterface;
use App\Repositories\TransactionRepositoryInterface;
use App\Support\Money;
use App\Support\Rules;
use App\Support\Str;

final class ProductsController
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly TransactionRepositoryInterface $transactions,
        private readonly TransactionManagerInterface $tx,
        private readonly View $view,
        private readonly Router $router,
        private readonly SessionInterface $session,
        private readonly Validator $validator,
    ) {
    }

    #[Route('GET', '/', name: 'home')]
    public function home(): Response
    {
        return Response::redirect($this->router->url('products.index'));
    }

    #[Route('GET', '/products', name: 'products.index')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) ($request->queryString('page', '1')));
        $sort = $request->queryString('sort', 'name') ?? 'name';
        $sort = in_array($sort, ['name', 'price', 'quantity_available'], true) ? $sort : 'name';
        $direction = strtolower($request->queryString('direction', 'asc') ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $search = trim($request->queryString('search', '') ?? '');

        $paginator = $this->products->paginate([
            'page' => $page,
            'per_page' => 9,
            'sort' => $sort,
            'direction' => $direction,
            'search' => $search,
        ]);

        return $this->view->render('products/index', [
            'paginator' => $paginator,
            'products' => $paginator,
            'query' => $request->query,
            'sort' => $sort,
            'direction' => $direction,
            'search' => $search,
        ]);
    }

    #[Route('GET', '/products/{slug}', name: 'products.show')]
    public function show(string $slug): Response
    {
        $product = $this->products->findBySlug($slug);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        return $this->view->render('products/show', ['product' => $product]);
    }

    #[Route('GET', '/products/{slug}/purchase', name: 'products.purchase')]
    #[Middleware('auth')]
    public function purchaseForm(string $slug): Response
    {
        return $this->view->render('products/purchase', [
            'product' => $this->findActiveProduct($slug),
            'errors' => [],
            'old' => [],
        ]);
    }

    #[Route('POST', '/products/{slug}/purchase', name: 'products.purchase.store')]
    #[Middleware('auth')]
    #[Middleware('csrf')]
    public function purchase(string $slug, Request $request): Response
    {
        try {
            $validated = $this->validator->validate($request->body, Rules::purchase());
        } catch (ValidationException $exception) {
            return $this->renderPurchaseForm($slug, $exception->errors(), $request->body);
        }

        $user = $request->attribute('user');

        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        try {
            $transaction = $this->purchaseProduct($slug, (int) $validated['quantity'], $user);
        } catch (ValidationException $exception) {
            return $this->renderPurchaseForm($slug, $exception->errors(), $request->body);
        }

        $this->session->flash('success', 'Purchase complete.');

        return Response::redirect($this->router->url('orders.show', ['id' => $transaction->id]));
    }

    #[Route('GET', '/orders/{id:\d+}', name: 'orders.show')]
    #[Middleware('auth')]
    public function receipt(int $id, Request $request): Response
    {
        $transaction = $this->transactions->findById($id);

        if ($transaction === null) {
            throw new HttpException(404, 'Order not found.');
        }

        $user = $request->attribute('user');

        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        if ($transaction->userId !== $user->id && !$user->isAdmin()) {
            throw new HttpException(403, 'You are not allowed to view that order.');
        }

        return $this->view->render('products/receipt', ['transaction' => $transaction]);
    }

    #[Route('POST', '/api/v1/products/{slug}/purchase', name: 'api.products.purchase')]
    #[Middleware('api_auth')]
    public function purchaseApi(string $slug, Request $request): Response
    {
        $user = $request->attribute('user');

        if (!$user instanceof User) {
            throw new HttpException(401, 'Authentication required.');
        }

        try {
            $validated = $this->validator->validate($request->body, Rules::purchase());
            $transaction = $this->purchaseProduct($slug, (int) $validated['quantity'], $user);
        } catch (ValidationException $exception) {
            return Response::error(
                'validation_failed',
                'The given data was invalid.',
                422,
                $exception->errors()
            );
        }

        $product = $this->products->findById($transaction->productId);

        return Response::json([
            'data' => [
                'transaction_id' => $transaction->id,
                'product' => $transaction->productName,
                'quantity' => $transaction->quantity,
                'unit_price' => $transaction->unitPrice,
                'total_price' => $transaction->totalPrice,
                'remaining_stock' => $product?->quantityAvailable,
            ],
        ], 201);
    }

    private function purchaseProduct(string $slug, int $quantity, User $buyer): Transaction
    {
        $product = $this->findActiveProduct($slug);

        if ($quantity > $product->quantityAvailable) {
            throw new ValidationException([
                'quantity' => ["Only {$product->quantityAvailable} left."],
            ]);
        }

        return $this->tx->transactional(function () use ($slug, $quantity, $buyer): Transaction {
            $locked = $this->products->findBySlugForUpdate($slug);

            if ($locked === null) {
                throw new HttpException(404, 'Product not found.');
            }

            if ($quantity > $locked->quantityAvailable) {
                throw new ValidationException([
                    'quantity' => ["Only {$locked->quantityAvailable} left."],
                ]);
            }

            $this->products->decrementStock($locked->id, $quantity);

            return $this->transactions->create([
                'user_id' => $buyer->id,
                'product_id' => $locked->id,
                'product_name' => $locked->name,
                'unit_price' => $locked->price,
                'quantity' => $quantity,
                'total_price' => Money::multiply($locked->price, $quantity),
            ]);
        });
    }

    private function findActiveProduct(string $slug): Product
    {
        $product = $this->products->findBySlug($slug);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        return $product;
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $old
     */
    private function renderPurchaseForm(string $slug, array $errors, array $old): Response
    {
        return $this->view->render('products/purchase', [
            'product' => $this->findActiveProduct($slug),
            'errors' => $errors,
            'old' => $old,
        ], 422);
    }

    #[Route('GET', '/admin/products', name: 'admin.products.index')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    public function adminIndex(Request $request): Response
    {
        $page = max(1, (int) ($request->queryString('page', '1')));

        return $this->view->render('admin/products/index', [
            'products' => $this->products->paginate([
                'page' => $page,
                'per_page' => 20,
                'include_inactive' => true,
            ]),
            'query' => $request->query,
        ]);
    }

    #[Route('GET', '/admin/products/create', name: 'admin.products.create')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    public function create(): Response
    {
        return $this->renderForm(null, [], []);
    }

    #[Route('POST', '/admin/products', name: 'admin.products.store')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    #[Middleware('csrf')]
    public function store(Request $request): Response
    {
        $data = $this->formData($request);

        if ($data['slug'] === '') {
            $data['slug'] = Str::slug($data['name']);
        }

        try {
            $validated = $this->validator->validate($data, Rules::product());

            if ($this->products->slugExists($validated['slug'])) {
                throw new ValidationException(['slug' => ['This slug is already in use.']]);
            }

            $this->products->create([
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] !== '' ? $validated['description'] : null,
                'price' => $validated['price'],
                'quantity_available' => (int) $validated['quantity_available'],
                'is_active' => (bool) $validated['is_active'],
            ]);
        } catch (ValidationException $exception) {
            return $this->renderForm(null, $exception->errors(), $data, 422);
        }

        $this->session->flash('success', 'Product created.');

        return Response::redirect($this->router->url('admin.products.index'));
    }

    #[Route('GET', '/admin/products/{id:\d+}/edit', name: 'admin.products.edit')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    public function edit(int $id): Response
    {
        return $this->renderForm($this->findProduct($id), [], []);
    }

    #[Route('POST', '/admin/products/{id:\d+}', name: 'admin.products.update')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    #[Middleware('csrf')]
    public function update(int $id, Request $request): Response
    {
        $product = $this->findProduct($id);
        $data = $this->formData($request, $product);

        if ($data['slug'] === '') {
            $data['slug'] = Str::slug($data['name']);
        }

        try {
            $validated = $this->validator->validate($data, Rules::product());

            if ($this->products->slugExists($validated['slug'], $product->id)) {
                throw new ValidationException(['slug' => ['This slug is already in use.']]);
            }

            $this->products->update($product->id, [
                'name' => $validated['name'],
                'slug' => $validated['slug'],
                'description' => $validated['description'] !== '' ? $validated['description'] : null,
                'price' => $validated['price'],
                'quantity_available' => (int) $validated['quantity_available'],
                'is_active' => (bool) $validated['is_active'],
            ]);
        } catch (ValidationException $exception) {
            return $this->renderForm($product, $exception->errors(), $data, 422);
        }

        $this->session->flash('success', 'Product updated.');

        return Response::redirect($this->router->url('admin.products.index'));
    }

    #[Route('POST', '/admin/products/{id:\d+}/delete', name: 'admin.products.destroy')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    #[Middleware('csrf')]
    public function destroy(int $id): Response
    {
        $product = $this->findProduct($id);

        $this->products->softDelete($product->id);
        $this->session->flash('success', 'Product removed from the store.');

        return Response::redirect($this->router->url('admin.products.index'));
    }

    private function findProduct(int $id): Product
    {
        $product = $this->products->findById($id);

        if ($product === null) {
            throw new HttpException(404, 'Product not found.');
        }

        return $product;
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $old
     */
    private function renderForm(?Product $product, array $errors, array $old, int $status = 200): Response
    {
        return $this->view->render('admin/products/form', [
            'product' => $product,
            'errors' => $errors,
            'old' => $old,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, ?Product $product = null): array
    {
        return [
            'name' => (string) ($request->input('name') ?? $product?->name ?? ''),
            'slug' => (string) ($request->input('slug') ?? $product?->slug ?? ''),
            'description' => (string) ($request->input('description') ?? $product?->description ?? ''),
            'price' => (string) ($request->input('price') ?? $product?->price ?? ''),
            'quantity_available' => (string) ($request->input('quantity_available') ?? $product?->quantityAvailable ?? ''),
            'is_active' => (int) filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
        ];
    }
}
