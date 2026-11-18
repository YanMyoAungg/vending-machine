<?php

declare(strict_types=1)
/** @var \App\Core\Router $router */
/** @var \App\Core\Csrf $csrf */
/** @var \App\Models\User|null $authUser */
?>
<header class="site-header">
    <a class="brand" href="/products">Vending Machine</a>

    <nav class="site-nav" aria-label="Main">
        <a href="/products">Shop</a>

        <?php if (($authUser ?? null)?->isAdmin()): ?>
            <a href="<?= escape(
                $router->url("admin.products.index"),
            ) ?>">Products</a>
            <a href="/admin/users">Users</a>
            <a href="/admin/transactions">Transactions</a>
        <?php endif; ?>

        <?php if (($authUser ?? null) !== null): ?>
            <details class="user-menu">
                <summary><?= escape($authUser->name) ?></summary>
                <div class="user-menu-dropdown">
                    <form method="post" action="/logout">
                        <?= $csrf->field() ?>
                        <button type="submit" class="link-button">Sign out</button>
                    </form>
                </div>
            </details>
        <?php else: ?>
            <a href="/login">Sign in</a>
        <?php endif; ?>
    </nav>
</header>
