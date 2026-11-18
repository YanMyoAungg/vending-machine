<?php

declare(strict_types=1);

use App\Core\Env;
use App\Database\Connection;
use App\Database\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$envFile = dirname(__DIR__) . '/.env';

if (is_file($envFile)) {
    Env::load($envFile);
}

$applied = (new Migrator(Connection::fromEnv(), dirname(__DIR__) . '/database/migrations'))->run();

if ($applied === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

foreach ($applied as $migration) {
    echo "Applied {$migration}\n";
}
