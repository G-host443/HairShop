<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Les tests d'intégration utilisent une base dédiée, jamais la base de développement.
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
if (!empty($_ENV['DB_TEST_DATABASE'])) {
    $_ENV['DB_DATABASE'] = $_ENV['DB_TEST_DATABASE'];
    $_SERVER['DB_DATABASE'] = $_ENV['DB_TEST_DATABASE'];
    putenv('DB_DATABASE=' . $_ENV['DB_TEST_DATABASE']);
}
