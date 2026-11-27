<?php

declare(strict_types=1);

return [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 5432),
    'database' => env('DB_DATABASE', 'hairstyle_garance'),
    'username' => env('DB_USERNAME', 'hairstyle_app'),
    'password' => env('DB_PASSWORD', ''),
    'test_database' => env('DB_TEST_DATABASE', 'hairstyle_garance_test'),
    'migrations_path' => base_path('database/migrations'),
    'seeders_namespace' => 'Database\\Seeders\\',
];
