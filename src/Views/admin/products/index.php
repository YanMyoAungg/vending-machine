<?php

declare(strict_types=1);
/** @var \App\Models\PaginatedResult $products */
/** @var \App\Core\Router $router */
/** @var \App\Core\Csrf $csrf */
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Admin</p>
        <h1>Products</h1>
    </div>
    <a class="button" href="<?= escape($router->url('admin.products.create')) ?>">New product</a>
</div>

<?php if ($products->items === []): ?>
    <p class="empty-state">No products yet. Create the first one.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Slug</th>
                    <th scope="col">Price</th>
                    <th scope="col">Stock</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products->items as $product): ?>
                    <tr>
                        <td data-label="Name"><a href="<?= escape($router->url('admin.products.edit', ['id' => $product->id])) ?>"><?= escape($product->name) ?></a></td>
                        <td data-label="Slug"><code><?= escape($product->slug) ?></code></td>
                        <td data-label="Price"><?= escape(money($product->price)) ?></td>
                        <td data-label="Stock"><?= $product->quantityAvailable ?></td>
                        <td data-label="Status">
                            <span class="badge <?= $product->isActive ? 'badge-active' : 'badge-inactive' ?>">
                                <?= $product->isActive ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="actions" data-label="Actions">
                            <a href="<?= escape($router->url('admin.products.edit', ['id' => $product->id])) ?>">Edit</a>
                            <form method="post" action="<?= escape($router->url('admin.products.destroy', ['id' => $product->id])) ?>" data-confirm="Remove this product from the store?">
                                <?= $csrf->field() ?>
                                <button type="submit" class="link-button danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $paginator = $products;
    $query = $query ?? [];
    $routeName = 'admin.products.index';
    require __DIR__ . '/../../partials/pagination.php';
    ?>
<?php endif; ?>
