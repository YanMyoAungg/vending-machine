<?php

declare(strict_types=1);
/** @var \App\Core\SessionInterface $session */
/** @var string $content */
$success = $session->pullFlash("success");
$error = $session->pullFlash("error");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= "Vending Machine" ?></title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <meta name="theme-color" content="#f7f2e8">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>

    <?php require __DIR__ . "/../partials/nav.php"; ?>

    <?php if ($success !== null): ?>
        <p class="alert alert-success" role="status"><?= escape(
            (string) $success,
        ) ?></p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="alert alert-error" role="alert"><?= escape(
            (string) $error,
        ) ?></p>
    <?php endif; ?>

    <main id="main" class="page">
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <p>Vending Machine — a PHP code exercise by Yan Myo Aung.</p>
    </footer>
</body>
</html>
