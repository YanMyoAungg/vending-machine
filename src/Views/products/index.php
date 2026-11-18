<?php

declare(strict_types=1);
/** @var \App\Models\PaginatedResult $products */
/** @var \App\Core\Router $router */
/** @var array<string, mixed> $query */
/** @var string $sort */
/** @var string $direction */
/** @var string $search */
$sortLink = static function (string $field) use (
    $router,
    $query,
    $sort,
    $direction,
): string {
    $nextDirection = $sort === $field && $direction === "asc" ? "desc" : "asc";

    return $router->url(
        "products.index",
        array_merge($query, [
            "sort" => $field,
            "direction" => $nextDirection,
        ]),
    );
}; ?>
<section class="storefront">
    <div class="page-heading">
        <div>
            <p class="eyebrow">The machine</p>
            <h1>Pick a drink</h1>
        </div>
        <p class="stock-note"><?= $products->total ?> products</p>
    </div>

    <form class="search-form" method="get" action="<?= escape(
        $router->url("products.index"),
    ) ?>" role="search">
        <label for="search">Search</label>
        <input id="search" type="search" name="search" value="<?= escape(
            $search,
        ) ?>" placeholder="Search products…">
        <button type="submit">Search</button>
    </form>

    <nav class="sort-links" aria-label="Sort products">
        <a href="<?= escape($sortLink("name")) ?>">Sort by name</a>
        <a href="<?= escape($sortLink("price")) ?>">Sort by price</a>
        <a href="<?= escape($sortLink("quantity_available")) ?>">Sort by stock</a>
    </nav>

    <?php if ($products->items === []): ?>
        <p class="empty-state">
            <?= $products->page > 1
                ? "No products on this page."
                : "No products are available right now." ?>
        </p>
        <?php if ($products->page > 1): ?>
            <p><a href="<?= escape(
                $router->url("products.index"),
            ) ?>">Back to the first page</a></p>
        <?php endif; ?>
    <?php else: ?>
        <ul class="product-grid">
            <?php foreach ($products->items as $product): ?>
                <li class="product-card">
                    <a class="product-link" href="<?= escape(
                        $router->url("products.show", [
                            "slug" => $product->slug,
                        ]),
                    ) ?>">
                        <span class="product-name"><?= escape(
                            $product->name,
                        ) ?></span>
                        <span class="product-price"><?= escape(
                            money($product->price),
                        ) ?></span>
                    </a>
                    <p class="product-meta">
                        <?= $product->quantityAvailable > 0
                            ? $product->quantityAvailable . " in stock"
                            : "Sold out" ?>
                    </p>
                    <a class="button button-small" href="/products/<?= escape(
                        $product->slug,
                    ) ?>/purchase">Buy</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
