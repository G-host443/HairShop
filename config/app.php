<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Hair_style by Garance'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Paris'),
    'locale' => env('APP_LOCALE', 'fr_FR'),
    'key' => env('APP_KEY', ''),
    'log_level' => env('LOG_LEVEL', 'warning'),

    // Middlewares exécutés pour toutes les requêtes HTTP, dans cet ordre.
    'middleware' => [
        App\Middleware\SecurityHeaders::class,
        App\Middleware\CountVisit::class,
        App\Middleware\StartSession::class,
        App\Middleware\VerifyCsrfToken::class,
    ],

    // Fichiers de routes chargés par le Kernel.
    'routes' => [
        'routes/api.php',
        'routes/admin.php',
        'routes/account.php',
        'routes/web.php', // en dernier : contient la route générique des pages CMS
    ],
];
