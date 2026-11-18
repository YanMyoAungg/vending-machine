<?php

declare(strict_types=1);
/** @var \App\Models\PaginatedResult $transactions */
/** @var \App\Core\Router $router */
/** @var array<string, mixed> $query */
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Admin</p>
        <h1>Transactions</h1>
    </div>
    <p class="stock-note"><?= $transactions->total ?> purchases</p>
</div>

<?php if ($transactions->items === []): ?>
    <p class="empty-state">No purchases yet.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Order</th>
                    <th scope="col">Buyer</th>
                    <th scope="col">Product</th>
                    <th scope="col">Qty</th>
                    <th scope="col">Unit price</th>
                    <th scope="col">Total</th>
                    <th scope="col">When</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions->items as $transaction): ?>
                    <tr>
                        <td data-label="Order">#<?= $transaction->id ?></td>
                        <td data-label="Buyer"><?= escape($transaction->buyerName ?? '') ?></td>
                        <td data-label="Product"><?= escape($transaction->productName) ?></td>
                        <td data-label="Qty"><?= $transaction->quantity ?></td>
                        <td data-label="Unit price"><?= escape(money($transaction->unitPrice)) ?></td>
                        <td data-label="Total"><?= escape(money($transaction->totalPrice)) ?></td>
                        <td data-label="When"><time datetime="<?= escape($transaction->createdAt) ?>"><?= escape($transaction->createdAt) ?></time></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $paginator = $transactions;
    $routeName = 'admin.transactions.index';
    require __DIR__ . '/../../partials/pagination.php';
    ?>
<?php endif; ?>
