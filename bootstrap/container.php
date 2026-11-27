<?php

declare(strict_types=1);

/**
 * Définitions du conteneur PHP-DI. Les classes sans dépendance scalaire sont auto-câblées
 * et n'ont pas besoin d'apparaître ici.
 */

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Router;
use App\Core\Session\ArraySessionStorage;
use App\Core\Session\NativeSessionStorage;
use App\Core\Session\SessionStorage;
use App\Services\Catalog\PricingService;
use App\Services\Catalog\PromotionService;
use App\Services\Mail\LogMailer;
use App\Services\Mail\MailerInterface;
use App\Services\Mail\SmtpMailer;
use App\Services\Settings\SettingsService;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    // Un seul routeur partagé (routes déclarées au démarrage, URL générées dans les vues).
    Router::class => DI\create(Router::class),

    Database::class => static fn (Config $config): Database => new Database(
        [
            'host' => (string) $config->get('database.host'),
            'port' => (int) $config->get('database.port'),
            'database' => (string) $config->get('database.database'),
            'username' => (string) $config->get('database.username'),
            'password' => (string) $config->get('database.password'),
        ],
        (string) $config->get('app.timezone', 'Europe/Paris'),
    ),

    LoggerInterface::class => static function (Config $config): LoggerInterface {
        $handler = new RotatingFileHandler(
            storage_path('logs/app.log'),
            14, // jours conservés
            Level::fromName(ucfirst(strtolower((string) $config->get('app.log_level', 'warning')))),
        );
        $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true, true));

        return new Logger('app', [$handler]);
    },

    Migrator::class => static fn (Database $db, Config $config): Migrator => new Migrator(
        $db,
        (string) $config->get('database.migrations_path'),
    ),

    // Sessions PHP natives sur le web ; en mémoire en console et pendant les tests.
    SessionStorage::class => static fn (Config $config): SessionStorage => PHP_SAPI === 'cli'
        ? new ArraySessionStorage()
        : new NativeSessionStorage($config->get('security.session')),

    MailerInterface::class => static function (Config $config, SettingsService $settings): MailerInterface {
        // Expéditeur : paramètres du site s'ils sont renseignés, sinon configuration.
        $fromAddress = $settings->string('notifications.sender_email') ?: (string) $config->get('mail.from_address');
        $fromName = $settings->string('notifications.sender_name') ?: (string) $config->get('mail.from_name');

        return match ($config->get('mail.driver')) {
            'log' => new LogMailer(storage_path('logs'), $fromAddress, $fromName),
            'smtp' => new SmtpMailer((string) $config->get('mail.dsn'), $fromAddress, $fromName),
            default => throw new RuntimeException('Pilote d\'e-mail inconnu : ' . $config->get('mail.driver')),
        };
    },

    // Un fichier de cache par base (développement / tests ne se mélangent pas) ; aucun cache sous PHPUnit.
    SettingsService::class => static fn (Database $db, Config $config): SettingsService => new SettingsService(
        $db,
        $config->get('app.env') === 'testing' ? '' : storage_path('cache/settings-' . $db->databaseName() . '.php'),
    ),

    // Fuseau horaire des paramètres du site (repli : configuration).
    Clock::class => static function (Config $config, SettingsService $settings): Clock {
        $zone = $settings->string('general.timezone', (string) $config->get('app.timezone', 'Europe/Paris'));

        return new Clock(in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Europe/Paris');
    },

    // Les promotions sont facultatives pour le calcul des prix (tests unitaires) mais toujours actives sur le site.
    PricingService::class => DI\autowire()->constructorParameter('promotions', DI\get(PromotionService::class)),

    ContainerInterface::class => DI\get(DI\Container::class),
];
