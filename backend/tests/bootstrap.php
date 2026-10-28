<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (class_exists(Dotenv::class)) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

// Functional tests TRUNCATE tables, so refuse to run against a non-test database.
$databaseName = ltrim((string) parse_url((string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? ''), PHP_URL_PATH), '/');
if (!str_ends_with($databaseName, '_test')) {
    fwrite(STDERR, sprintf("Refusing to run tests: DATABASE_URL database \"%s\" must end with \"_test\".\n", $databaseName));
    exit(1);
}
