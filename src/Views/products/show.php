<?php

declare(strict_types=1);
/** @var \App\Models\Product $product */
?>
<article class="product-detail receipt-panel">
    <p class="eyebrow">Product</p>
    <h1><?= escape($product->name) ?></h1>

    <?php if ($product->description !== null && $product->description !== ''): ?>
        <p class="lede"><?= escape($product->description) ?></p>
    <?php endif; ?>

    <dl class="price-list">
        <div>
            <dt>Price</dt>
            <dd><?= escape(money($product->price)) ?></dd>
        </div>
        <div>
            <dt>Availability</dt>
            <dd><?= $product->quantityAvailable > 0 ? $product->quantityAvailable . ' in stock' : 'Sold out' ?></dd>
        </div>
    </dl>

    <?php if ($product->quantityAvailable > 0): ?>
        <a class="button" href="/products/<?= escape($product->slug) ?>/purchase">Buy this drink</a>
    <?php else: ?>
        <p class="empty-state">This product is sold out.</p>
    <?php endif; ?>

    <p class="back-link"><a href="<?= escape($router->url('products.index')) ?>">Back to all products</a></p>
</article>
