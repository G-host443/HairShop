<?php

declare(strict_types=1);

/**
 * Amorçage commun au web (public/index.php), à la console (bin/console) et aux tests.
 * Renvoie le conteneur d'injection de dépendances prêt à l'emploi.
 */

use App\Core\Config;
use App\Core\Router;
use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);

// 1. Variables d'environnement (.env facultatif : en production elles peuvent venir du serveur).
Dotenv::createImmutable($root)->safeLoad();

// 2. Configuration.
$config = Config::fromDirectory($root . '/config');
date_default_timezone_set((string) $config->get('app.timezone', 'Europe/Paris'));
mb_internal_encoding('UTF-8');

// 3. Conteneur.
$builder = new ContainerBuilder();
$builder->useAutowiring(true);
$builder->addDefinitions(require __DIR__ . '/container.php');
$builder->addDefinitions([Config::class => $config]);

if ($config->get('app.env') === 'production') {
    $builder->enableCompilation($root . '/storage/cache/di');
}

/** @var Container $container */
$container = $builder->build();
$container->set(Container::class, $container);

// 4. Gestion globale des erreurs PHP (avertissements → exceptions, erreurs fatales journalisées).
//    Sous PHPUnit, c'est PHPUnit qui gère les erreurs.
if ($config->get('app.env') !== 'testing') {
    App\Core\ErrorHandler::register($container->get(LoggerInterface::class), (bool) $config->get('app.debug'));
}

// 5. Routes.
$router = $container->get(Router::class);
foreach ($config->get('app.routes', []) as $file) {
    (static function (Router $router) use ($root, $file): void {
        require $root . '/' . $file;
    })($router);
}

return $container;
