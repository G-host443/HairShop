<?php

declare(strict_types=1);

/**
 * Routeur du serveur intégré de PHP (développement uniquement) :
 *     php -S localhost:8000 -t public public/router.php
 *
 * Sert directement les fichiers statiques existants, sauf les scripts PHP,
 * et renvoie tout le reste vers index.php (comme le .htaccess sous Apache).
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath(__DIR__ . $path);

if ($file !== false && is_file($file) && str_starts_with($file, __DIR__) && !str_ends_with($file, '.php')) {
    return false;
}

require __DIR__ . '/index.php';
