<?php

declare(strict_types=1);
/** @var \App\Models\Product $product */
/** @var array<string, list<string>> $errors */
/** @var array<string, mixed> $old */
/** @var \App\Core\Csrf $csrf */
$quantity = max(1, (int) ($old['quantity'] ?? 1));
?>
<article class="purchase-layout">
    <section class="receipt-panel">
        <p class="eyebrow">Checkout</p>
        <h1><?= escape($product->name) ?></h1>
        <?php if ($product->description !== null && $product->description !== ''): ?>
            <p class="lede"><?= escape($product->description) ?></p>
        <?php endif; ?>

        <dl class="price-list">
            <div><dt>Unit price</dt><dd><?= escape(money($product->price)) ?></dd></div>
            <div><dt>In stock</dt><dd><?= $product->quantityAvailable ?></dd></div>
        </dl>
    </section>

    <section class="purchase-form-panel">
        <?php require __DIR__ . '/../partials/errors.php'; ?>

        <?php if ($product->quantityAvailable === 0): ?>
            <p class="empty-state">This product is sold out.</p>
        <?php else: ?>
            <form method="post" action="/products/<?= escape($product->slug) ?>/purchase" class="stack-form" data-validate>
                <?= $csrf->field() ?>

                <div class="field">
                    <label for="quantity">Quantity</label>
                    <input
                        id="quantity"
                        name="quantity"
                        type="number"
                        min="1"
                        max="<?= $product->quantityAvailable ?>"
                        value="<?= $quantity ?>"
                        data-quantity-input
                        data-unit-price="<?= escape($product->price) ?>"
                        data-rules="required|integer"
                        required
                    >
                </div>

                <p class="total-line">
                    <span>Total</span>
                    <strong data-quantity-total><?= escape(money(\App\Support\Money::multiply($product->price, $quantity))) ?></strong>
                </p>

                <button type="submit">Complete purchase</button>
            </form>
        <?php endif; ?>

        <p class="back-link"><a href="/products/<?= escape($product->slug) ?>">Back to product</a></p>
    </section>
</article>
