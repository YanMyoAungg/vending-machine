<?php

declare(strict_types=1);
/** @var \App\Models\User|null $user */
/** @var array<string, list<string>> $errors */
/** @var array<string, mixed> $old */
/** @var \App\Core\Router $router */
/** @var \App\Core\Csrf $csrf */
$isEdit = $user !== null;
$active = array_key_exists('is_active', $old) ? (bool) $old['is_active'] : ($user?->isActive ?? true);
$role = (string) ($old['role'] ?? $user?->role ?? \App\Models\User::ROLE_USER);
$fieldError = static fn (string $field): ?string => $errors[$field][0] ?? null;
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Admin</p>
        <h1><?= $isEdit ? 'Edit user' : 'New user' ?></h1>
    </div>
    <a href="<?= escape($router->url('admin.users.index')) ?>">Back to users</a>
</div>

<?php require __DIR__ . '/../../partials/errors.php'; ?>

<form class="stack-form" method="post" action="<?= $isEdit ? escape($router->url('admin.users.update', ['id' => $user->id])) : escape($router->url('admin.users.store')) ?>" data-validate>
    <?= $csrf->field() ?>

    <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" value="<?= escape((string) ($old['name'] ?? $user?->name ?? '')) ?>" data-rules="required|min:2|max:100" required>
        <?php if ($fieldError('name') !== null): ?><p class="field-error"><?= escape($fieldError('name')) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="<?= escape((string) ($old['email'] ?? $user?->email ?? '')) ?>" data-rules="required|email" required>
        <?php if ($fieldError('email') !== null): ?><p class="field-error"><?= escape($fieldError('email')) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="role">Role</label>
        <select id="role" name="role">
            <option value="user" <?= $role === 'user' ? 'selected' : '' ?>>User</option>
            <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>Admin</option>
        </select>
        <?php if ($fieldError('role') !== null): ?><p class="field-error"><?= escape($fieldError('role')) ?></p><?php endif; ?>
    </div>

    <div class="field">
        <label for="password">
            <?= $isEdit ? 'New password' : 'Password' ?>
            <span class="hint"><?= $isEdit ? 'leave blank to keep the current password' : 'at least 8 characters' ?></span>
        </label>
        <input id="password" type="password" name="password" <?= $isEdit ? '' : 'required' ?> data-rules="<?= $isEdit ? 'min:8' : 'required|min:8' ?>" autocomplete="new-password">
        <?php if ($fieldError('password') !== null): ?><p class="field-error"><?= escape($fieldError('password')) ?></p><?php endif; ?>
    </div>

    <label class="checkbox">
        <input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>>
        Active account
    </label>

    <div class="form-actions">
        <button type="submit"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        <a href="<?= escape($router->url('admin.users.index')) ?>">Cancel</a>
    </div>
</form>
