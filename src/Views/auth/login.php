<?php declare(strict_types=1) ?>
<section class="auth-card">
    <h1>Sign in</h1>
    <p>Use the demo account to buy drinks or manage the machine.</p>

    <?php if (($errors["email"][0] ?? null) !== null): ?>
        <p role="alert"><?= escape($errors["email"][0]) ?></p>
    <?php endif; ?>

    <form method="post" action="/login" data-validate>
        <?= $csrf->field() ?>
        <input type="hidden" name="next" value="<?= escape($next ?? "") ?>">

        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="<?= escape(
            $old["email"] ?? "",
        ) ?>" data-rules="required|email" required autocomplete="email">

        <label for="password">Password</label>
        <input id="password" type="password" name="password" data-rules="required" required autocomplete="current-password">

        <button type="submit">Sign in</button>
    </form>

    <p class="hint">Demo: admin@vending.test / password</p>
    <p class="hint">Demo: user@vending.test / password</p>
    <p><a href="/products">Back to the machine</a></p>
</section>
