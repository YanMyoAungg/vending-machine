<?php

declare(strict_types=1);

use App\Core\Env;
use App\Database\Connection;
use App\Database\Seeder;

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';

if (is_file($envFile)) {
    Env::load($envFile);
}

(new Seeder(Connection::fromEnv()))->run();

echo "Database seeded.\n";
