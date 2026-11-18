<?php

declare(strict_types=1);
/** @var \App\Models\Transaction $transaction */
?>
<article class="receipt-panel receipt">
    <p class="eyebrow">Receipt</p>
    <h1>Order #<?= $transaction->id ?></h1>
    <p class="lede">Thanks — your drink is dispensed.</p>

    <dl class="receipt-lines">
        <div><dt>Product</dt><dd><?= escape($transaction->productName) ?></dd></div>
        <div><dt>Quantity</dt><dd><?= $transaction->quantity ?></dd></div>
        <div><dt>Unit price</dt><dd><?= escape(money($transaction->unitPrice)) ?></dd></div>
        <div class="receipt-total"><dt>Total</dt><dd><?= escape(money($transaction->totalPrice)) ?></dd></div>
        <div><dt>Bought by</dt><dd><?= escape($transaction->buyerName ?? '') ?></dd></div>
        <div><dt>When</dt><dd><?= escape($transaction->createdAt) ?></dd></div>
    </dl>

    <p class="back-link"><a href="<?= escape($router->url('products.index')) ?>">Back to the machine</a></p>
</article>
