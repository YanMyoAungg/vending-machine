<?php

declare(strict_types=1);
/** @var array<string, list<string>> $errors */
?>
<?php if (($errors ?? []) !== []): ?>
    <div class="alert alert-error" role="alert">
        <ul>
            <?php foreach ($errors as $messages): ?>
                <?php foreach ($messages as $message): ?>
                    <li><?= escape($message) ?></li>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
