<?php

declare(strict_types=1);

/**
 * Points d'accès JSON (/api) utilisés par le JavaScript du site et de l'administration.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Api\AvailabilityApiController;
use App\Controllers\Api\MediaApiController;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\RequirePermission;

$router->group(['prefix' => '/api'], static function (Router $router): void {
    // Créneaux disponibles pour une prestation configurée (public : utilisé par le parcours de réservation).
    $router->get('/disponibilites', [AvailabilityApiController::class, 'index'], 'api.availability');

    // --- Administration (session admin requise) ---
    $router->group(['prefix' => '/admin', 'middleware' => [Authenticate::class]], static function (Router $router): void {
        $router->get('/medias', [MediaApiController::class, 'index'], 'api.admin.media', [RequirePermission::class . ':media.manage']);
    });
});
