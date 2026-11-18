<?php

declare(strict_types=1);
/** @var \App\Models\Product|null $product */
/** @var array<string, list<string>> $errors */
/** @var array<string, mixed> $old */
/** @var \App\Core\Router $router */
/** @var \App\Core\Csrf $csrf */
$isEdit = $product !== null;
$active = array_key_exists('is_active', $old) ? (bool) $old['is_active'] : ($product?->isActive ?? true);
$fieldError = static fn (string $field): ?string => $errors[$field][0] ?? null;
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Admin</p>
        <h1><?= $isEdit ? 'Edit product' : 'New product' ?></h1>
    </div>
    <a href="<?= escape($router->url('admin.products.index')) ?>">Back to products</a>
</div>

<?php require __DIR__ . '/../../partials/errors.php'; ?>

<form class="stack-form" method="post" action="<?= $isEdit ? escape($router->url('admin.products.update', ['id' => $product->id])) : escape($router->url('admin.products.store')) ?>" data-validate>
    <?= $csrf->field() ?>

    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" value="<?= escape((string) ($old['name'] ?? $product?->name ?? '')) ?>" data-rules="required|min:2|max:100" required>
        <?php if ($fieldError('name') !== null): ?><p class="field-error"><?= escape($fieldError('name')) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="slug">Slug <span class="hint">optional — derived from the name</span></label>
        <input id="slug" name="slug" value="<?= escape((string) ($old['slug'] ?? $product?->slug ?? '')) ?>">
        <?php if ($fieldError('slug') !== null): ?><p class="field-error"><?= escape($fieldError('slug')) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description" rows="3"><?= escape((string) ($old['description'] ?? $product?->description ?? '')) ?></textarea>
        <?php if ($fieldError('description') !== null): ?><p class="field-error"><?= escape($fieldError('description')) ?></p><?php endif; ?>
    </div>

    <div class="field-row">
        <div class="field">
            <label for="price">Price (USD)</label>
            <input id="price" name="price" type="number" step="0.001" min="0.001" max="9999.999" value="<?= escape((string) ($old['price'] ?? $product?->price ?? '')) ?>" data-rules="required|decimal" required>
            <?php if ($fieldError('price') !== null): ?><p class="field-error"><?= escape($fieldError('price')) ?></p><?php endif; ?>
        </div>

        <div class="field">
            <label for="quantity_available">Quantity available</label>
            <input id="quantity_available" name="quantity_available" inputmode="numeric" value="<?= escape((string) ($old['quantity_available'] ?? $product?->quantityAvailable ?? '')) ?>" data-rules="required|integer" required>
            <?php if ($fieldError('quantity_available') !== null): ?><p class="field-error"><?= escape($fieldError('quantity_available')) ?></p><?php endif; ?>
        </div>
    </div>

    <label class="checkbox">
        <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
        Active in the store
    </label>

    <div class="form-actions">
        <button type="submit"><?= $isEdit ? 'Save changes' : 'Create product' ?></button>
        <a href="<?= escape($router->url('admin.products.index')) ?>">Cancel</a>
    </div>
</form>
