<?php

declare(strict_types=1);
/** @var \App\Models\PaginatedResult $paginator */
/** @var \App\Core\Router $router */
/** @var array<string, mixed> $query */
/** @var string $routeName */
$routeName = $routeName ?? 'products.index';
$query = $query ?? [];
$previous = array_merge($query, ['page' => max(1, $paginator->page - 1)]);
$next = array_merge($query, ['page' => $paginator->page + 1]);
?>
<?php if ($paginator->lastPage() > 1): ?>
    <nav class="pagination" aria-label="Pagination">
        <?php if ($paginator->hasPrevious()): ?>
            <a href="<?= escape($router->url($routeName, $previous)) ?>" rel="prev">Previous</a>
        <?php endif; ?>

        <span>Page <?= $paginator->page ?> of <?= $paginator->lastPage() ?></span>

        <?php if ($paginator->hasNext()): ?>
            <a href="<?= escape($router->url($routeName, $next)) ?>" rel="next">Next</a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
