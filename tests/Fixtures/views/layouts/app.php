<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Fixture</title></head>
<body>
<header>Layout</header>
<main><?= $content ?></main>
<?php if (isset($brand)): ?><footer><?= escape($brand) ?></footer><?php endif; ?>
</body>
</html>
