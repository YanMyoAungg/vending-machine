<?php

declare(strict_types=1);
/** @var \App\Models\PaginatedResult $users */
/** @var \App\Core\Router $router */
/** @var \App\Core\Csrf $csrf */
/** @var array<string, mixed> $query */
/** @var string $search */
?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Admin</p>
        <h1>Users</h1>
    </div>
    <a class="button" href="<?= escape($router->url('admin.users.create')) ?>">New user</a>
</div>

<form class="search-form" method="get" action="<?= escape($router->url('admin.users.index')) ?>" role="search">
    <label for="search">Search</label>
    <input id="search" type="search" name="search" value="<?= escape($search) ?>" placeholder="Name or email">
    <button type="submit">Search</button>
</form>

<?php if ($users->items === []): ?>
    <p class="empty-state">No users match that search.</p>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Status</th>
                    <th scope="col"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users->items as $user): ?>
                    <tr>
                        <td data-label="Name"><a href="<?= escape($router->url('admin.users.edit', ['id' => $user->id])) ?>"><?= escape($user->name) ?></a></td>
                        <td data-label="Email"><?= escape($user->email) ?></td>
                        <td data-label="Role"><span class="badge"><?= escape(ucfirst($user->role)) ?></span></td>
                        <td data-label="Status">
                            <span class="badge <?= $user->isActive ? 'badge-active' : 'badge-inactive' ?>">
                                <?= $user->isActive ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="actions" data-label="Actions">
                            <a href="<?= escape($router->url('admin.users.edit', ['id' => $user->id])) ?>">Edit</a>
                            <form method="post" action="<?= escape($router->url('admin.users.destroy', ['id' => $user->id])) ?>" data-confirm="<?= $user->isActive ? 'Deactivate this user?' : 'Activate this user?' ?>">
                                <?= $csrf->field() ?>
                                <button type="submit" class="link-button <?= $user->isActive ? 'danger' : '' ?>"><?= $user->isActive ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php
    $paginator = $users;
    $routeName = 'admin.users.index';
    require __DIR__ . '/../../partials/pagination.php';
    ?>
<?php endif; ?>
